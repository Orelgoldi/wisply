/* Wisply Chatbot v2.0.0 — Shadow DOM + voice + lead capture (white-label) */
(function () {
  'use strict';

  const CFG = window.WisplyConfig || {};
  const S   = CFG.settings || {};

  let host   = null;   // the page-level anchor element
  let shadow = null;   // the shadow root we render into

  // Scoped DOM helpers — everything lives inside the shadow root
  const $  = sel => shadow ? shadow.querySelector(sel) : null;
  const $id = id => shadow ? shadow.getElementById(id) : null;

  /* ── Language ── */
  const SUPPORTED = ['he','en','ru','ar'];
  /* Languages the site owner turned on (comma list from settings). Empty → all. */
  const ENABLED = (() => {
    const raw = String(S.enabled_langs || '').toLowerCase();
    const list = raw.split(',').map(s => s.trim()).filter(s => SUPPORTED.includes(s));
    const uniq = list.filter((l, i) => list.indexOf(l) === i);
    return uniq.length ? uniq : SUPPORTED.slice();
  })();
  const DEFAULT_LANG = ENABLED.includes(String(S.default_lang || '').toLowerCase())
    ? String(S.default_lang).toLowerCase() : ENABLED[0];
  const langLabel = l => l === 'he' ? 'עב' : l === 'ar' ? 'ع' : l.toUpperCase();
  let lang = (() => {
    const saved = sessionStorage.getItem('wisply_lang');
    if (saved && ENABLED.includes(saved)) return saved;
    const l = (CFG.lang || 'he_IL').slice(0, 2).toLowerCase();
    return ENABLED.includes(l) ? l : DEFAULT_LANG;
  })();
  /* Switch language WITHOUT rebuilding the whole widget. Rebuilding (replacing the
     entire shadow DOM + re-wiring every listener) was fragile and could leave the
     widget unresponsive ("stuck") on mobile. Instead we just relabel in place. */
  function setLang(l) {
    if (!ENABLED.includes(l) || l === lang) return;
    lang = l;
    sessionStorage.setItem('wisply_lang', l);
    try { stopListen(); stopAudio(); endVoice(); } catch (e) {}
    pageQuestions = [];        // questions are language-specific — reload for the new language
    applyLang();
    // If the visitor hasn't chatted yet, re-greet in the new language; otherwise keep history
    if (!hadActivity) { const m = $id('m-msgs'); if (m) { m.innerHTML = ''; greeting(); } }
    fetchPageQuestions();
  }

  /* Update only the visible labels for the current language — no DOM rebuild. */
  function applyLang() {
    const set = (id, txt) => { const el = $id(id); if (el) el.textContent = txt; };
    set('m-name', botName());
    set('m-online', t('online'));
    const ta = $id('m-textarea'); if (ta) ta.placeholder = t('placeholder');
    const mic = $id('m-mic'); if (mic) { mic.setAttribute('aria-label', t('voice_call')); mic.title = t('voice_call'); }
    const cs = $('#m-call span'); if (cs) cs.textContent = t('call');
    const ms = $('#m-map span');  if (ms) ms.textContent = t('dir');
    shadow.querySelectorAll('.m-lang').forEach(b => {
      const on = b.dataset.l === lang;
      b.classList.toggle('on', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    if ($id('m-suggestions')) { clearSuggestions(); showSuggestions(); }
  }

  /* ── Session ── */
  function newSid() {
    return 'wisply-' + Date.now().toString(36) + Math.random().toString(36).slice(2);
  }
  let sid = (() => {
    let id = sessionStorage.getItem('wisply_sid');
    if (!id) { id = newSid(); sessionStorage.setItem('wisply_sid', id); }
    return id;
  })();

  /* ── Inactivity timeout ── */
  const IDLE_MS = 15 * 60 * 1000;   // 15 minutes
  let idleTimer = null;
  let hadActivity = false;          // did the user send anything this session?
  let chatEnded  = false;           // conversation closed (idle timeout / message limit)

  function resetIdle() {
    clearTimeout(idleTimer);
    idleTimer = setTimeout(onIdle, IDLE_MS);
  }
  function onIdle() {
    if (!hadActivity) return;       // nothing to end
    endChat({ he:'⏱️ השיחה הסתיימה עקב חוסר פעילות.',
              en:'⏱️ The conversation ended due to inactivity.',
              ru:'⏱️ Разговор завершён из-за неактивности.',
              ar:'⏱️ انتهت المحادثة بسبب عدم النشاط.' }[lang] || '');
  }

  /* Managed end (FR-007A): never end without an action — freeze the composer and
     offer a fresh start. Shared by the idle timeout and the message limit (F3). */
  function endChat(note) {
    if (chatEnded) return;
    chatEnded = true;
    clearTimeout(idleTimer);
    // Start a fresh conversation for the next message
    sid = newSid();
    sessionStorage.setItem('wisply_sid', sid);
    hadActivity = false;
    setComposer(false);

    const msgs = $id('m-msgs');
    if (!msgs) return;
    const el = document.createElement('div');
    el.className = 'm-sys';
    el.innerHTML = `<div>${esc(note)}</div>`;
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'm-newchat-btn';
    btn.textContent = t('new_chat');
    btn.addEventListener('click', startNewChat);
    el.appendChild(btn);
    msgs.appendChild(el);
    scroll(msgs);
  }

  /* Enable/disable the composer (input + send + mic) while a chat is closed */
  function setComposer(on) {
    const ta = $id('m-textarea');
    if (ta) { if (!on) { ta.value = ''; ta.style.height = ''; } ta.disabled = !on; }
    const snd = $id('m-send');
    if (snd) snd.disabled = !on || !(ta && ta.value.trim());
    const mic = $id('m-mic');
    if (mic) mic.disabled = !on;
    const img = $id('m-img');
    if (img) img.disabled = !on;
  }

  function startNewChat() {
    sid = newSid();
    sessionStorage.setItem('wisply_sid', sid);
    hadActivity = false;
    chatEnded = false;
    const m = $id('m-msgs');
    if (m) m.innerHTML = '';
    setComposer(true);
    greeting();
    resetIdle();
    setTimeout(() => $id('m-textarea')?.focus(), 50);
  }

  /* ── i18n ── */
  const T = {
    he: {
      placeholder:'הקלד/י הודעה...', online:'משיב/ה בדרך כלל מיד', call:'התקשרו', dir:'ניווט',
      error:'אופס, משהו השתבש. נסו שוב או צרו קשר טלפוני.',
      lead_title:'נשמח לחזור אליכם — השאירו פרטים', lead_name:'שם מלא',
      lead_phone:'טלפון', lead_email:'אימייל (לא חובה)', lead_btn:'שליחה', lead_ok:'תודה! ניצור איתכם קשר בהקדם.',
      lead_required:'נא למלא את כל שדות החובה', lead_contact:'נא להשאיר טלפון או אימייל כדי שנוכל לחזור אליכם', call_now:'📞 התקשרו עכשיו',
      chat_ended:'✅ השיחה הסתיימה. תודה שפניתם אלינו!',
      suggest:'שאלות נפוצות', what_to_know:'מה תרצה/י לדעת?', new_chat:'התחל/י שיחה חדשה',
      ask_yes:'כן, אשמח', ask_no:'לא, תודה', ask_declined:'בסדר גמור! אני כאן אם תצטרך/י עוד מידע 😊',
      chips:['מה אתם מציעים?','איך יוצרים איתכם קשר?','איפה אתם ממוקמים?','מהן שעות הפעילות?'],
      voice_call:'שיחת קול', vm_hint:'אפשר לדבר חופשי — אענה לכם בקול',
      vm_listen:'מקשיב/ה...', vm_think:'חושב/ת...', vm_speak:'מדבר/ת...',
      vm_muted:'המיקרופון מושתק', vm_error:'רגע, ננסה שוב...',
      vm_mute:'השתקת מיקרופון', vm_end:'סיום שיחה', vm_connecting:'מתחבר...',
    },
    en: {
      placeholder:'Type a message...', online:'Usually replies instantly', call:'Call us', dir:'Directions',
      error:'Oops, something went wrong. Please try again or call us.',
      lead_title:'Leave your details and we\'ll get back to you', lead_name:'Full name',
      lead_phone:'Phone', lead_email:'Email (optional)', lead_btn:'Send', lead_ok:'Thank you! We\'ll be in touch shortly.',
      lead_required:'Please fill in all required fields', lead_contact:'Please leave a phone or an email so we can get back to you', call_now:'📞 Call us now',
      chat_ended:'✅ The conversation has ended. Thank you!',
      suggest:'Popular questions', what_to_know:'What would you like to know?', new_chat:'Start a new chat',
      ask_yes:'Yes, please', ask_no:'No, thanks', ask_declined:'No problem! I\'m here if you need more info 😊',
      chips:['What do you offer?','How can I contact you?','Where are you located?','What are your opening hours?'],
      voice_call:'Voice chat', vm_hint:'Just speak freely — I\'ll reply out loud',
      vm_listen:'Listening...', vm_think:'Thinking...', vm_speak:'Speaking...',
      vm_muted:'Microphone muted', vm_error:'One moment, let\'s try again...',
      vm_mute:'Mute microphone', vm_end:'End call', vm_connecting:'Connecting...',
    },
    ru: {
      placeholder:'Введите сообщение...', online:'Обычно отвечает сразу', call:'Позвонить', dir:'Маршрут',
      error:'Упс, что-то пошло не так. Попробуйте снова или позвоните нам.',
      lead_title:'Оставьте контакты, и мы свяжемся с вами', lead_name:'Полное имя',
      lead_phone:'Телефон', lead_email:'Email (необязательно)', lead_btn:'Отправить', lead_ok:'Спасибо! Мы скоро свяжемся.',
      lead_required:'Пожалуйста, заполните все обязательные поля', lead_contact:'Оставьте телефон или email, чтобы мы могли связаться с вами', call_now:'📞 Позвонить сейчас',
      chat_ended:'✅ Разговор завершён. Спасибо!',
      suggest:'Частые вопросы', what_to_know:'Что вы хотите узнать?', new_chat:'Начать новый чат',
      ask_yes:'Да, с удовольствием', ask_no:'Нет, спасибо', ask_declined:'Хорошо! Я здесь, если понадобится 😊',
      chips:['Что вы предлагаете?','Как с вами связаться?','Где вы находитесь?','Какие у вас часы работы?'],
      voice_call:'Голосовой чат', vm_hint:'Просто говорите — я отвечу голосом',
      vm_listen:'Слушаю...', vm_think:'Думаю...', vm_speak:'Говорю...',
      vm_muted:'Микрофон выключен', vm_error:'Секунду, попробуем снова...',
      vm_mute:'Выключить микрофон', vm_end:'Завершить', vm_connecting:'Соединение...',
    },
    ar: {
      placeholder:'اكتب رسالة...', online:'يرد عادةً على الفور', call:'اتصلوا', dir:'الاتجاهات',
      error:'عذرًا، حدث خطأ ما. حاولوا مرة أخرى أو اتصلوا بنا هاتفيًا.',
      lead_title:'يسعدنا التواصل معكم — اتركوا التفاصيل', lead_name:'الاسم الكامل',
      lead_phone:'الهاتف', lead_email:'البريد الإلكتروني (اختياري)', lead_btn:'إرسال', lead_ok:'شكرًا! سنتواصل معكم قريبًا.',
      lead_required:'يرجى تعبئة جميع الحقول المطلوبة', lead_contact:'يرجى ترك رقم هاتف أو بريد إلكتروني حتى نتمكن من التواصل معكم', call_now:'📞 اتصلوا الآن',
      chat_ended:'✅ انتهت المحادثة. شكرًا لتواصلكم معنا!',
      suggest:'أسئلة شائعة', what_to_know:'ماذا تريد أن تعرف؟', new_chat:'بدء محادثة جديدة',
      ask_yes:'نعم، بكل سرور', ask_no:'لا، شكرًا', ask_declined:'لا مشكلة! أنا هنا إذا احتجت المزيد من المعلومات 😊',
      chips:['ماذا تقدّمون؟','كيف يمكنني التواصل معكم؟','أين تقعون؟','ما هي ساعات العمل؟'],
      voice_call:'مكالمة صوتية', vm_hint:'تحدث بحرية — سأرد عليك صوتيًا',
      vm_listen:'أستمع...', vm_think:'أفكر...', vm_speak:'أتحدث...',
      vm_muted:'الميكروفون مكتوم', vm_error:'لحظة، سنحاول مرة أخرى...',
      vm_mute:'كتم الميكروفون', vm_end:'إنهاء المكالمة', vm_connecting:'جارٍ الاتصال...',
    },
  };
  const t = k => (T[lang] || T.he)[k];

  /* ── SVG icons ── */
  const ICO_CHAT = `<svg class="ico-chat" viewBox="0 0 24 24"><path d="M20 2H4a2 2 0 00-2 2v18l4-4h14a2 2 0 002-2V4a2 2 0 00-2-2z"/></svg>`;
  const ICO_X    = `<svg class="ico-close" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>`;
  const ICO_BOT  = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="7" width="18" height="13" rx="2.5"/><circle cx="9" cy="13" r="1.4" fill="currentColor" stroke="none"/><circle cx="15" cy="13" r="1.4" fill="currentColor" stroke="none"/><path stroke-linecap="round" d="M12 2.5v4.5M8.5 2.5h7"/></svg>`;
  const ICO_SEND = `<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>`;
  const ICO_MIC  = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="2" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0014 0M12 18v3"/></svg>`;
  const ICO_SPEAKER_ON  = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5L6 9H2v6h4l5 4V5z"/><path d="M15.5 8.5a5 5 0 010 7M19 5a9 9 0 010 14"/></svg>`;
  const ICO_SPEAKER_OFF = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5L6 9H2v6h4l5 4V5z"/><path d="M22 9l-6 6M16 9l6 6"/></svg>`;
  const ICO_MIC_OFF = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 9v3a3 3 0 005.12 2.12M15 9.34V5a3 3 0 00-5.94-.6"/><path d="M17 16.95A7 7 0 015 12M12 18v3"/><path d="M2 2l20 20"/></svg>`;
  const ICO_PHONE_DOWN = `<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5a16 16 0 0118 0v3a2 2 0 01-2 2l-2.5-.3a1.5 1.5 0 01-1.3-1.2l-.3-1.6a1.5 1.5 0 00-1.1-1.1 12 12 0 00-5.6 0 1.5 1.5 0 00-1.1 1.1l-.3 1.6a1.5 1.5 0 01-1.3 1.2L3 15.5a2 2 0 01-2-2v-3z"/></svg>`;
  const ICO_CAMERA = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8.5A2 2 0 015 6.5h2.2l1.3-2h7l1.3 2H19a2 2 0 012 2V18a2 2 0 01-2 2H5a2 2 0 01-2-2V8.5z"/><circle cx="12" cy="13" r="3.4"/></svg>`;

  /* ── Avatars: the bot's "face" in the header and teaser. Preset line icons (they
     inherit the header's white via currentColor) plus a custom uploaded image. ── */
  const AVATARS = {
    robot:   `<svg viewBox="0 0 24 24" fill="currentColor" fill-rule="evenodd" clip-rule="evenodd"><path d="M13 2.4a1.4 1.4 0 10-2 1.25V5.2H8.2A3.7 3.7 0 004.5 8.9v5.4a3.7 3.7 0 003.7 3.7h7.6a3.7 3.7 0 003.7-3.7V8.9a3.7 3.7 0 00-3.7-3.7H13V3.65A1.4 1.4 0 0013 2.4zM9.6 10.4a1.7 1.7 0 100 3.4 1.7 1.7 0 000-3.4zm4.8 0a1.7 1.7 0 100 3.4 1.7 1.7 0 000-3.4z"/><path d="M2.6 10.5a1 1 0 011 1v2a1 1 0 11-2 0v-2a1 1 0 011-1zm18.8 0a1 1 0 011 1v2a1 1 0 11-2 0v-2a1 1 0 011-1z"/></svg>`,
    sparkle: `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M11.5 2.3c.5 3.7 2.2 5.4 5.9 5.9-3.7.5-5.4 2.2-5.9 5.9-.5-3.7-2.2-5.4-5.9-5.9 3.7-.5 5.4-2.2 5.9-5.9z"/><path d="M18 13c.25 1.9 1.1 2.75 3 3-1.9.25-2.75 1.1-3 3-.25-1.9-1.1-2.75-3-3 1.9-.25 2.75-1.1 3-3z"/></svg>`,
    chat:    `<svg viewBox="0 0 24 24" fill="currentColor" fill-rule="evenodd" clip-rule="evenodd"><path d="M6.5 3.5A2.75 2.75 0 003.75 6.25v8A2.75 2.75 0 006.5 17H8v3.1a1 1 0 001.64.77L14 17h3.5a2.75 2.75 0 002.75-2.75v-8A2.75 2.75 0 0017.5 3.5h-11zM9 8.4a1.3 1.3 0 100 2.6 1.3 1.3 0 000-2.6zm6 0a1.3 1.3 0 100 2.6 1.3 1.3 0 000-2.6zm-6.2 4.3a1 1 0 00-1.6 1.15 5.5 5.5 0 009.6 0 1 1 0 10-1.7-1.05 3.5 3.5 0 01-6.3 0z"/></svg>`,
    headset: `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 3a8.5 8.5 0 00-8.5 8.5v.5a1 1 0 001 1H6v-1.5a6 6 0 0112 0V17a2.5 2.5 0 01-2.5 2.5h-2.1a1.4 1.4 0 100 1.5H15.5A4 4 0 0019.4 17.6 1.5 1.5 0 0020.5 12v-.5A8.5 8.5 0 0012 3z"/><rect x="3" y="11.6" width="4" height="6.4" rx="2"/><rect x="17" y="11.6" width="4" height="6.4" rx="2"/></svg>`,
    person:  `<svg viewBox="0 0 24 24" fill="currentColor" fill-rule="evenodd" clip-rule="evenodd"><path d="M12 2.4a9.6 9.6 0 100 19.2 9.6 9.6 0 000-19.2zM8.7 9a1.45 1.45 0 100 2.9 1.45 1.45 0 000-2.9zm6.6 0a1.45 1.45 0 100 2.9 1.45 1.45 0 000-2.9zM7.9 14.1a1 1 0 00-1.65 1.15 7 7 0 0011.5 0A1 1 0 1016.1 14.1a5 5 0 01-8.2 0z"/></svg>`,
    heart:   `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 21.2S3.3 15.6 3.3 9.6C3.3 6.4 5.8 4.2 8.6 4.2c1.8 0 3.1.9 3.4 2.1.3-1.2 1.6-2.1 3.4-2.1 2.8 0 5.3 2.2 5.3 5.4 0 6-8.7 11.6-8.7 11.6z"/></svg>`,
    store:   `<svg viewBox="0 0 24 24" fill="currentColor" fill-rule="evenodd" clip-rule="evenodd"><path d="M8 6a4 4 0 118 0h2.2a1.5 1.5 0 011.49 1.33l1.2 11A1.5 1.5 0 0119.4 20H4.6a1.5 1.5 0 01-1.49-1.67l1.2-11A1.5 1.5 0 015.8 6H8zm2 0h4a2 2 0 10-4 0zm-.5 4.5a1 1 0 10-2 0 4.5 4.5 0 009 0 1 1 0 10-2 0 2.5 2.5 0 01-5 0z"/></svg>`,
    spark:   `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M13.7 2.2a.6.6 0 00-1.08-.14L5.3 12.4a.7.7 0 00.57 1.1H10l-1.6 8a.6.6 0 001.08.46l7.3-10.3a.7.7 0 00-.57-1.1H12l1.7-8.36z"/></svg>`,
  };
  function avatarImg(src) {
    return `<img src="${esc(src)}" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;display:block">`;
  }
  function avatarHtml() {
    const key = S.bot_avatar || 'av-1';
    if (key === 'custom' && S.bot_avatar_url) return avatarImg(S.bot_avatar_url);
    // Bundled illustrated character avatars (av-1 … av-8), served from the plugin.
    if (/^av-\d+$/.test(key) && CFG.avatarBase) return avatarImg(CFG.avatarBase + key + '.png?v=' + (CFG.avatarVer || ''));
    return AVATARS[key] || AVATARS.robot;   // legacy monochrome icon presets
  }

  /* ── Build (renders into the shadow root) ── */
  function build() {
    if (!host) return;

    // Apply brand colours on the HOST — custom props inherit into the shadow tree
    if (S.primary_color)   host.style.setProperty('--c-teal-600', S.primary_color);
    if (S.secondary_color) host.style.setProperty('--c-teal-700', S.secondary_color);
    if (S.bubble_position === 'bottom-left') host.setAttribute('data-pos', 'left');

    const cssUrl = CFG.cssUrl || '';

    shadow.innerHTML = `
      <link rel="stylesheet" href="${cssUrl}">

      <button class="m-fab" id="m-fab" aria-expanded="false" aria-controls="m-win" aria-label="פתח/י צ׳אט">
        ${ICO_CHAT}${ICO_X}
      </button>

      <div class="m-win" id="m-win" role="dialog" aria-modal="true" aria-label="${esc(botName())}" hidden>

        <header class="m-head">
          <div class="m-head-av">${avatarHtml()}</div>
          <div class="m-head-info">
            <div class="m-head-name" id="m-name">${botName()}</div>
            <div class="m-head-status"><span class="m-status-dot"></span><span id="m-online">${t('online')}</span></div>
          </div>
          <button class="m-head-close" id="m-close" aria-label="סגור/י">✕</button>
        </header>

        <div class="m-msgs" id="m-msgs" role="log" aria-live="polite"></div>

        ${(S.phone || S.map_url) ? `
        <div class="m-actions">
          ${S.phone   ? `<button class="m-act" id="m-call">📞 <span>${t('call')}</span></button>` : ''}
          ${S.map_url ? `<button class="m-act" id="m-map">📍 <span>${t('dir')}</span></button>` : ''}
        </div>` : ''}

        <div class="m-input-area">
          <button class="m-mic-btn" id="m-mic" aria-label="${t('voice_call')}" title="${t('voice_call')}">${ICO_MIC}</button>
          <textarea id="m-textarea" class="m-textarea" rows="1"
            placeholder="${t('placeholder')}" maxlength="500" aria-label="הקלד/י הודעה" autocomplete="off"></textarea>
          ${VISUAL_SEARCH ? `<button class="m-mic-btn m-img-btn" id="m-img" aria-label="${WOO_T.img_search}" title="${WOO_T.img_search}">${ICO_CAMERA}</button>
          <input type="file" id="m-img-file" accept="image/*" hidden>` : ''}
          <button class="m-send-btn" id="m-send" disabled aria-label="שליחה">${ICO_SEND}</button>
        </div>

        <footer class="m-foot">
          <button class="m-speaker" id="m-speaker" aria-label="הקראה קולית" title="הקראת תשובות בקול">${ICO_SPEAKER_OFF}</button>
          ${poweredBy()}
          ${ENABLED.length > 1 ? `<div class="m-langs" role="group" aria-label="בחירת שפה">
            ${ENABLED.map(l =>
              `<button class="m-lang${lang===l?' on':''}" data-l="${l}" aria-pressed="${lang===l}">${langLabel(l)}</button>`
            ).join('')}
          </div>` : ''}
        </footer>
      </div>`;

    wire();
    greeting();
  }

  function botName()  {
    return (S.bot_name && S.bot_name.trim())
        || S['widget_title_'+lang] || S.widget_title_he
        || ({ he:'עוזר חכם', en:'Smart Assistant', ru:'Умный помощник', ar:'مساعد ذكي' }[lang] || 'Assistant');
  }
  function shortName(){ return (S.bot_name && S.bot_name.trim()) || botName().split(/[—–-]/)[0].trim() || botName(); }

  /* "Powered by" vendor credit (white-label product name). */
  function poweredBy() {
    if ((S.powered_by_enabled ?? '1') === '0') return '<span class="m-brand"></span>';
    const name = (S.product_name && S.product_name.trim()) || 'Wisply';
    const word = { he:'מופעל ע״י', en:'Powered by', ru:'Работает на', ar:'مشغّل بواسطة' }[lang] || 'Powered by';
    return `<span class="m-brand">${word} ${esc(name)}</span>`;
  }

  /* Suggested questions: admin-configured (one per line) override the defaults. */
  function suggestedChips() {
    const raw = S['suggested_questions_' + lang];
    if (raw && raw.trim()) {
      const lines = raw.split('\n').map(s => s.trim()).filter(Boolean);
      if (lines.length) return lines;
    }
    return t('chips');
  }

  /* ── Wire events ──
     Fully null-safe: a single missing element must never throw and prevent the
     rest (especially the language buttons) from being wired — that would freeze
     the widget after a rebuild/language switch. */
  function wire() {
    // Language buttons first — these must always work so switching never gets stuck
    shadow.querySelectorAll('.m-lang').forEach(b =>
      b.addEventListener('click', () => setLang(b.dataset.l))
    );

    $id('m-fab')?.addEventListener('click', toggle);
    $id('m-close')?.addEventListener('click', close);

    // Scroll isolation. Some host sites run a smooth-scroll / scroll-hijack library
    // (Lenis, Locomotive, fullPage, page-builder motion effects) that listens on
    // window/document and preventDefaults wheel/touch globally — which freezes
    // scrolling INSIDE our widget too, in BOTH axes (the classic "can't scroll the chat
    // at all" on some sites). Stop these events from bubbling out of the message area so
    // the page handler never sees them; the browser still scrolls our own containers
    // (the vertical list and the horizontal product carousel) natively. One listener on
    // the container covers its children, including carousels added later.
    const msgsEl = $id('m-msgs');
    if (msgsEl) {
      ['wheel', 'touchstart', 'touchmove'].forEach(evt =>
        msgsEl.addEventListener(evt, e => e.stopPropagation(), { passive: true })
      );
    }

    const ta  = $id('m-textarea');
    const snd = $id('m-send');
    snd?.addEventListener('click', send);
    ta?.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } });
    ta?.addEventListener('input', () => { if (snd) snd.disabled = !ta.value.trim(); autoH(ta); });

    // Visual product search — the button only proxies to the hidden file input
    const imgBtn = $id('m-img');
    const imgIn  = $id('m-img-file');
    imgBtn?.addEventListener('click', () => imgIn?.click());
    imgIn?.addEventListener('change', () => {
      const f = imgIn.files && imgIn.files[0];
      imgIn.value = '';           // allow re-picking the same file
      if (f) imageSearch(f);
    });

    $id('m-call')?.addEventListener('click', () => location.href = 'tel:' + (S.phone||'').replace(/[^\d+]/g,''));
    $id('m-map')?.addEventListener('click',  () => window.open(S.map_url,'_blank','noopener'));

    // Voice: mic (speech-to-text) + speaker toggle (text-to-speech)
    const mic = $id('m-mic');
    const spk = $id('m-speaker');
    if (mic) {
      if (!VOICE_ON || !MIC_AVAILABLE) mic.style.display = 'none';
      mic.addEventListener('click', () => {
        unlockAudio();   // must run synchronously inside the tap so mobile allows playback
        (USE_REALTIME || (USE_OPENAI_STT && USE_OPENAI_TTS)) ? openVoiceMode() : toggleListen();
      });
    }
    if (spk) {
      if (!VOICE_ON || !TTS_AVAILABLE) spk.style.display = 'none';
      spk.addEventListener('click', toggleSpeak);
    }
    updateSpeakerUI();
  }

  /* ── Open/close ── */
  let isOpen = false;
  function toggle() { isOpen ? close() : show(); }

  function show(focusInput = true) {
    isOpen = true;
    $id('m-teaser')?.remove();   // dismiss the proactive bubble once the chat opens
    $id('m-fab')?.setAttribute('aria-expanded', 'true');
    $id('m-win')?.removeAttribute('hidden');
    // Only steal focus (and pop the mobile keyboard) on a real open — not on rebuilds
    if (focusInput) setTimeout(() => $id('m-textarea')?.focus(), 260);
    resetIdle();
  }

  /* ── Proactive page-aware teaser ──
     After a configurable delay, if the visitor is on a specific page (e.g. a
     workshop or department) and hasn't engaged, pop a contextual bubble offering
     more info — to spark interaction. Shows once per page, per session. */
  const PROACTIVE_ON  = (S.proactive_enabled ?? '1') !== '0';
  const PROACTIVE_MS  = Math.max(1, parseInt(S.proactive_delay || '5', 10)) * 1000;

  /* ── Desktop auto-open (call-to-action): open the full window automatically ── */
  const IS_DESKTOP    = window.matchMedia('(min-width: 768px)').matches;
  const AUTOOPEN_ON   = IS_DESKTOP && (S.desktop_autoopen_enabled ?? '0') === '1';
  const AUTOOPEN_MS   = Math.max(0, parseInt(S.desktop_autoopen_delay || '3', 10)) * 1000;
  function autoOpenMessage() { return S['desktop_autoopen_msg_' + lang] || S.desktop_autoopen_msg_he || ''; }
  let pageTitle = '';
  let department = '';
  let pageQuestions = [];   // AI-generated questions about the current page

  /* ── Context Engine (FR-001): capture campaign/source once per session ── */
  function captureContext() {
    if (!sessionStorage.getItem('wisply_landing')) {
      sessionStorage.setItem('wisply_landing', location.href);
      const p = new URLSearchParams(location.search);
      sessionStorage.setItem('wisply_utm_source',   p.get('utm_source')   || '');
      sessionStorage.setItem('wisply_utm_medium',   p.get('utm_medium')   || '');
      sessionStorage.setItem('wisply_utm_campaign', p.get('utm_campaign') || '');
      sessionStorage.setItem('wisply_referrer',     document.referrer     || '');
    }
  }
  function leadContext() {
    return {
      utm_source:   sessionStorage.getItem('wisply_utm_source')   || '',
      utm_medium:   sessionStorage.getItem('wisply_utm_medium')   || '',
      utm_campaign: sessionStorage.getItem('wisply_utm_campaign') || '',
      referrer:     sessionStorage.getItem('wisply_referrer')     || '',
      landing_page: sessionStorage.getItem('wisply_landing')      || '',
      department:   department || '',
      page_title:   pageTitle || '',
    };
  }

  /* Fetch (and cache) 4 page-specific questions, then refresh the chips if showing */
  function fetchPageQuestions() {
    if (!pageTitle || !PROACTIVE_ON) return;
    const key = 'wisply_pq_' + lang + '_' + location.pathname;
    const cached = sessionStorage.getItem(key);
    if (cached) {
      try { pageQuestions = JSON.parse(cached) || []; } catch { pageQuestions = []; }
      refreshSuggestions();
      if (pageQuestions.length) return;
    }
    fetch(CFG.apiUrl + '/page-questions', {
      method: 'POST',
      headers: { 'Content-Type':'application/json', 'X-WP-Nonce': CFG.nonce },
      body: JSON.stringify({ title: pageTitle, url: location.href, lang }),
    })
      .then(r => r.json())
      .then(d => {
        if (d && Array.isArray(d.questions) && d.questions.length) {
          pageQuestions = d.questions;
          sessionStorage.setItem(key, JSON.stringify(pageQuestions));
          refreshSuggestions();
        }
      })
      .catch(() => {});
  }

  /* If the suggestion chips are on screen and untouched, swap in the page questions */
  function refreshSuggestions() {
    if (!hadActivity && $id('m-suggestions')) { clearSuggestions(); showSuggestions(); }
  }

  function proactiveMessage() {
    const subject = pageTitle.trim();
    if (!subject) return '';                       // only on specific, titled pages
    const tpl = S['proactive_msg_' + lang] || S.proactive_msg_he || '';
    if (tpl && tpl.includes('{subject}')) return tpl.replace('{subject}', subject);
    return ({
      he: 'היי 👋 אשמח לתת לך עוד פרטים על ' + subject + '. יש לך שאלה?',
      en: 'Hi 👋 Happy to tell you more about ' + subject + '. Any questions?',
      ru: 'Здравствуйте 👋 Расскажу подробнее о ' + subject + '. Есть вопросы?',
      ar: 'مرحبًا 👋 يسعدني أن أخبرك المزيد عن ' + subject + '. هل لديك سؤال؟'
    }[lang]) || '';
  }

  let teaserShown = false;   // per page-load (shows again on every fresh load)

  /* Trigger: when the visitor scrolls to the middle of the page (or, on pages too
     short to scroll, after the configured delay). Fires once per page load. */
  function setupTeaserTrigger() {
    if (!PROACTIVE_ON || !pageTitle) return;
    const scrollable = (document.documentElement.scrollHeight - window.innerHeight);
    if (scrollable < 200) {            // short page — can't reach "middle", use the delay
      setTimeout(showTeaser, PROACTIVE_MS);
      return;
    }
    const onScroll = () => {
      if (teaserShown) { window.removeEventListener('scroll', onScroll); return; }
      const sc  = window.scrollY || document.documentElement.scrollTop || 0;
      const max = document.documentElement.scrollHeight - window.innerHeight;
      if (max > 0 && (sc / max) >= 0.5) {
        window.removeEventListener('scroll', onScroll);
        showTeaser();
      }
    };
    window.addEventListener('scroll', onScroll, { passive: true });
  }

  function showTeaser() {
    if (!PROACTIVE_ON || isOpen || hadActivity || teaserShown) return;
    const msg = proactiveMessage();
    if (!msg) return;
    teaserShown = true;

    const el = document.createElement('div');
    el.className = 'm-teaser';
    el.id = 'm-teaser';
    el.setAttribute('role', 'button');
    el.setAttribute('tabindex', '0');
    el.innerHTML =
      `<button class="m-teaser-x" id="m-teaser-x" aria-label="סגור">✕</button>
       <div class="m-teaser-av">${avatarHtml()}</div>
       <div class="m-teaser-text">${esc(msg)}</div>`;
    shadow.appendChild(el);

    el.querySelector('#m-teaser-x').addEventListener('click', e => { e.stopPropagation(); el.remove(); });
    const openFromTeaser = () => { el.remove(); proactiveOpen(); };
    el.addEventListener('click', openFromTeaser);
    el.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openFromTeaser(); } });

    // Auto-dismiss if ignored
    setTimeout(() => $id('m-teaser')?.remove(), 16000);
  }

  function proactiveOpen() {
    // The chat was already built with the contextual greeting + page questions,
    // so opening it simply reveals that conversation.
    show();
  }

  /* ── Desktop auto-open trigger — fire once per session, only if untouched ── */
  function setupAutoOpen() {
    if (sessionStorage.getItem('wisply_autoopened')) return;   // don't nag on every page
    setTimeout(() => {
      if (isOpen || hadActivity) return;                       // respect the visitor
      sessionStorage.setItem('wisply_autoopened', '1');
      show(false);                                             // open without stealing focus/keyboard
    }, AUTOOPEN_MS);
  }

  function close() {
    isOpen = false;
    stopListen();
    stopAudio();
    endVoice();
    $id('m-fab').setAttribute('aria-expanded', 'false');
    $id('m-win').setAttribute('hidden', '');
    $id('m-fab').focus();
  }

  /* ── Greeting + suggestions ── */
  function greeting() {
    // Desktop auto-open call-to-action greeting takes precedence when enabled
    if (AUTOOPEN_ON) {
      const am = autoOpenMessage();
      if (am) { botMsg(am, []); showSuggestions(); return; }
    }
    // On a specific page (with proactive on), open with the contextual offer
    if (pageTitle && PROACTIVE_ON) {
      const msg = proactiveMessage();
      botMsg(msg || (S['greeting_'+lang] || S.greeting_he || ''));
    } else {
      const g = S['greeting_'+lang] || S.greeting_he || '';
      if (g) botMsg(g, []);
    }
    showSuggestions();
  }

  function showSuggestions() {
    // On a specific page, prefer the AI-generated page questions
    const usePage = pageTitle && PROACTIVE_ON && pageQuestions.length > 0;
    const chips   = usePage ? pageQuestions : suggestedChips();
    const label   = usePage ? t('what_to_know') : t('suggest');

    const msgs = $id('m-msgs');
    if (!msgs) return;
    const wrap = document.createElement('div');
    wrap.className = 'm-suggestions';
    wrap.id = 'm-suggestions';
    wrap.innerHTML = `
      <span class="m-suggest-label">${esc(label)}</span>
      <div class="m-suggest-chips">
        ${chips.map(c => `<button class="m-chip" type="button">${esc(c)}</button>`).join('')}
      </div>`;
    msgs.appendChild(wrap);
    wrap.querySelectorAll('.m-chip').forEach(chip =>
      chip.addEventListener('click', () => sendText(chip.textContent))
    );
    scroll(msgs);
  }

  function clearSuggestions() { $id('m-suggestions')?.remove(); }

  /* ── Send ── */
  function send() { sendText($id('m-textarea').value.trim()); }

  async function sendText(text) {
    if (!text || chatEnded) return;
    clearSuggestions();
    hadActivity = true;
    resetIdle();

    const ta = $id('m-textarea');
    if (ta) { ta.value = ''; ta.style.height = ''; }
    const snd = $id('m-send');
    if (snd) snd.disabled = true;

    userMsg(text);
    const dots = typing();

    try {
      const res = await fetch(CFG.apiUrl + '/chat', {
        method: 'POST',
        headers: { 'Content-Type':'application/json', 'X-WP-Nonce': CFG.nonce },
        body: JSON.stringify({ message:text, session_id:sid, lang, page_url:location.href }),
      });
      dots.remove();
      if (!res.ok) throw new Error(res.status);
      const d = await res.json();

      // Parse interactive markers from the reply
      let reply = d.reply || '';
      const actionMatch = reply.match(/\[ACTION:([a-z_]+)\]/i);
      const optsMatch   = reply.match(/\[OPTIONS:([^\]]+)\]/i);
      const prodMatch   = reply.match(/\[PRODUCTS:([^\]]+)\]/i);
      const suggestMatch= reply.match(/\[SUGGEST:([^\]]+)\]/i);
      const wantsOrderForm = /\[ORDER_FORM\]/i.test(reply);
      const askLead     = /\[ASK_LEAD\]/i.test(reply);
      const showForm    = /\[SHOW_LEAD_FORM\]/i.test(reply);
      const emergency   = /\[EMERGENCY\]/i.test(reply);

      const cleanReply = reply
        .replace(/\[ACTION:[a-z_]+\]/ig, '')
        .replace(/\[OPTIONS:[^\]]+\]/ig, '')
        .replace(/\[PRODUCTS:[^\]]*\]/ig, '')
        .replace(/\[SUGGEST:[^\]]*\]/ig, '')
        .replace(/\[ORDER_FORM\]/ig, '')
        .replace(/\[ASK_LEAD\]/ig, '')
        .replace(/\[SHOW_LEAD_FORM\]/ig, '')
        .replace(/\[EMERGENCY\]/ig, '')
        .trim();

      // Message limit reached (F3) — the server closes the conversation on this reply
      const ended = !!d.conversation_ended;

      if (cleanReply) botMsg(cleanReply);   // empty during a human-mode relay
      // Product cards: the model's explicit [PRODUCTS:] selection wins; otherwise the
      // server's product_ids, but ONLY when it flagged show_products (a real shopping
      // intent — browse / price / category / buy / order). Plain info answers set
      // show_products=false, so they no longer drag a carousel along. This restores
      // carousels for genuine product queries without depending on the model's marker.
      const cardIds = prodMatch
        ? parseIds(prodMatch[1])
        : (d.show_products && Array.isArray(d.product_ids) ? d.product_ids : []);
      if (cardIds.length) productCards(cardIds);
      // Complementary picks for the bundle flow — but only once the guiding questions
      // are done. If this reply still asks a question ([OPTIONS] present), hold the
      // results back so we never show products before the customer answered.
      if (suggestMatch && !optsMatch) productSuggest(suggestMatch[1].trim());
      if (wantsOrderForm && !ended) orderStatusForm();
      // Human handoff: a click-to-WhatsApp button to the agent, pre-filled with context.
      if (d.handoff_url) handoffButton(d.handoff_url, d.handoff_label);
      // Live two-way bridge: agent answers from WhatsApp, visitor stays here.
      if (d.human) enterHumanMode();
      if (emergency)  emergencyButtons();
      // Follow-up prompts would be dead ends once the conversation is over
      if (!ended) {
        if (optsMatch) optionButtons(optsMatch[1].split('|').map(s => s.trim()).filter(Boolean));
        if (askLead)   askLeadButtons();
        if (showForm)  leadForm();
      }
      if (actionMatch) actionButton(actionMatch[1].toLowerCase());
      speak(cleanReply);   // read the answer aloud if voice output is on

      // Close out: CTA first, then end the chat exactly like the idle timeout does
      if (ended) { endCta(); endChat(t('chat_ended')); }
    } catch {
      dots.remove();
      botMsg(t('error'), []);
    }
  }

  /* ── Render ── */
  function botMsg(text /* sources intentionally not shown */) {
    const msgs = $id('m-msgs');
    const el = document.createElement('div');
    el.className = 'm-msg bot';
    el.innerHTML = `
      <div class="m-bub">${fmt(text)}</div>
      <div class="m-ts">${shortName()} · ${clock()}</div>`;
    msgs.appendChild(el);
    scroll(msgs);
  }

  function userMsg(text) {
    const msgs = $id('m-msgs');
    const el = document.createElement('div');
    el.className = 'm-msg user';
    el.innerHTML = `
      <div class="m-bub">${esc(text)}</div>
      <div class="m-ts">${clock()}</div>`;
    msgs.appendChild(el);
    scroll(msgs);
  }

  function typing() {
    const msgs = $id('m-msgs');
    const el = document.createElement('div');
    el.className = 'm-msg bot';
    el.innerHTML = `<div class="m-bub" style="padding:6px 14px"><div class="m-typing"><span></span><span></span><span></span></div></div>`;
    msgs.appendChild(el);
    scroll(msgs);
    return el;
  }

  function systemNote(text) {
    const msgs = $id('m-msgs');
    if (!msgs) return;
    const el = document.createElement('div');
    el.className = 'm-sys';
    el.textContent = text;
    msgs.appendChild(el);
    scroll(msgs);
  }

  /* ── Lead form ──
     Each field is admin-configurable (F1): required | optional | hidden. */
  const LEAD_FIELDS = ['name', 'phone', 'email'];
  const FIELD_MODE  = {
    name:  S.lead_field_name  || 'required',
    phone: S.lead_field_phone || 'required',
    email: S.lead_field_email || 'optional',
  };
  const fieldOn  = k => FIELD_MODE[k] !== 'hidden';
  const fieldReq = k => FIELD_MODE[k] === 'required';

  /* Field label; required fields get a ' *' marker. The default labels carry an
     "(optional)" hint — drop it when the admin made the field mandatory. */
  function fieldLabel(k) {
    const base = t('lead_' + k);
    return fieldReq(k) ? base.replace(/\s*\([^)]*\)\s*$/, '') + ' *' : base;
  }

  /* Only the fields the admin actually shows are sent to /lead */
  function leadFields(fd) {
    const out = {};
    LEAD_FIELDS.forEach(k => { if (fieldOn(k)) out[k] = String(fd.get(k) || '').trim(); });
    return out;
  }

  function leadForm() {
    const msgs = $id('m-msgs');
    const card = document.createElement('div');
    card.className = 'm-lead';
    const fid = 'mlf' + Date.now();
    const fsid = sid;   // pin the conversation this lead belongs to — sid may rotate later
    const consentText = S['consent_text_'+lang] || S.consent_text_he || '';
    const consentReq  = (S.consent_required ?? '1') !== '0';
    const consentHtml = consentText ? `
        <label class="m-consent">
          <input type="checkbox" name="consent" ${consentReq ? 'required' : ''}>
          <span>${esc(consentText)}</span>
        </label>` : '';

    const inputs = {
      name:  k => `<input type="text"  name="name"  placeholder="${esc(fieldLabel(k))}" aria-label="${esc(fieldLabel(k))}" autocomplete="name">`,
      phone: k => `<input type="tel"   name="phone" placeholder="${esc(fieldLabel(k))}" aria-label="${esc(fieldLabel(k))}" autocomplete="tel">`,
      email: k => `<input type="email" name="email" placeholder="${esc(fieldLabel(k))}" aria-label="${esc(fieldLabel(k))}" autocomplete="email">`,
    };
    const fieldsHtml = LEAD_FIELDS.filter(fieldOn).map(k => inputs[k](k)).join('\n        ');

    card.innerHTML = `
      <strong>${t('lead_title')}</strong>
      <form id="${fid}" style="display:flex;flex-direction:column;gap:8px">
        ${fieldsHtml}
        ${consentHtml}
        <div class="m-lead-err" role="alert" hidden style="color:#b3261e;font-size:12px;font-weight:600"></div>
        <button type="submit" class="m-lead-btn">${t('lead_btn')}</button>
      </form>`;
    msgs.appendChild(card);
    scroll(msgs);

    card.querySelector('form').addEventListener('submit', async e => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const err = card.querySelector('.m-lead-err');

      // Required fields are validated here (not via the `required` attribute) so we
      // can show one inline message instead of the browser's native bubble
      const missing = LEAD_FIELDS.filter(k => fieldOn(k) && fieldReq(k) && !String(fd.get(k) || '').trim());
      if (missing.length) {
        if (err) { err.textContent = t('lead_required'); err.hidden = false; }
        card.querySelector(`[name="${missing[0]}"]`)?.focus();
        return;
      }
      // Mirror the server rule: if any contact field is on screen, one of them must be
      // filled. Without this the server 422s a form we happily let them submit.
      if ((fieldOn('phone') || fieldOn('email')) &&
          !String(fd.get('phone') || '').trim() && !String(fd.get('email') || '').trim()) {
        if (err) { err.textContent = t('lead_contact'); err.hidden = false; }
        card.querySelector('[name="phone"], [name="email"]')?.focus();
        return;
      }
      if (err) err.hidden = true;

      const consent = consentText ? !!fd.get('consent') : true;
      const btn = e.target.querySelector('button[type="submit"]');
      btn.disabled = true;
      try {
        const res = await fetch(CFG.apiUrl + '/lead', {
          method:'POST',
          headers:{'Content-Type':'application/json','X-WP-Nonce':CFG.nonce},
          body: JSON.stringify({
            ...leadFields(fd),
            session_id:fsid, lang, page_url:location.href,
            consent: consent ? 1 : 0,
            consent_text: consentText,
            ...leadContext(),
          }),
        });
        if (!res.ok) {
          // Show the server's reason (missing field / no contact method / consent)
          // instead of a click that silently does nothing.
          let msg = '';
          try { msg = (await res.json()).error || ''; } catch {}
          if (err) { err.textContent = msg || t('error'); err.hidden = false; }
          btn.disabled = false;
          return;
        }
      } catch {
        if (err) { err.textContent = t('error'); err.hidden = false; }
        btn.disabled = false;
        return;
      }
      card.innerHTML = `<div style="text-align:center;padding:8px;color:var(--c-teal-600);font-weight:700">✓ ${t('lead_ok')}</div>`;
    });
  }

  /* ── End-of-conversation CTA (F2) ──
     conversation_end_action: 'lead' | 'call' | 'both' | 'none' */
  function endCta() {
    const mode = S.conversation_end_action || 'lead';
    if (mode === 'none') return;
    if (mode === 'call' || mode === 'both') callButton();
    if (mode === 'lead' || mode === 'both') leadForm();
  }

  /* Prominent tel: button — same phone source as the header call action (S.phone) */
  function callButton() {
    const phone = (S.phone || '').trim();
    if (!phone) return;             // nothing configured — silently skip
    const msgs = $id('m-msgs');
    if (!msgs) return;
    const a = document.createElement('a');
    a.className = 'm-action-link m-call-cta';
    a.href = 'tel:' + phone.replace(/[^\d+]/g, '');
    a.textContent = t('call_now');
    msgs.appendChild(a);
    scroll(msgs);
  }

  /* ── Emergency resources (101 + mental-health hotlines) ── */
  function emergencyButtons() {
    const msgs = $id('m-msgs');
    if (!msgs) return;
    const phone = (S.emergency_phone || '101').replace(/[^\d+]/g, '');
    const eran  = S.emergency_eran_url  || 'https://www.eran.org.il/';
    const sahar = S.emergency_sahar_url || 'https://sahar.org.il/';
    const wrap = document.createElement('div');
    wrap.className = 'm-emerg';
    wrap.innerHTML =
      `<a class="m-emerg-btn call" href="tel:${esc(phone)}">📞 חיוג ${esc(S.emergency_phone || '101')} (חירום)</a>
       ${eran  ? `<a class="m-emerg-btn" href="${esc(eran)}"  target="_blank" rel="noopener">💬 ער"ן — עזרה ראשונה נפשית</a>` : ''}
       ${sahar ? `<a class="m-emerg-btn" href="${esc(sahar)}" target="_blank" rel="noopener">🤍 סהר — תמיכה אנונימית</a>` : ''}`;
    msgs.appendChild(wrap);
    scroll(msgs);
  }

  /* ── Guided flow: choice buttons + yes/no lead question ── */
  function optionButtons(opts) {
    if (!opts.length) return;
    const msgs = $id('m-msgs');
    if (!msgs) return;
    const wrap = document.createElement('div');
    wrap.className = 'm-opts';
    wrap.innerHTML = opts.map(o => `<button class="m-chip" type="button">${esc(o)}</button>`).join('');
    msgs.appendChild(wrap);
    wrap.querySelectorAll('.m-chip').forEach((b, i) =>
      b.addEventListener('click', () => { wrap.remove(); sendText(opts[i]); })
    );
    scroll(msgs);
  }

  function askLeadButtons() {
    const msgs = $id('m-msgs');
    if (!msgs) return;
    const wrap = document.createElement('div');
    wrap.className = 'm-yn';
    wrap.innerHTML =
      `<button class="m-yn-btn yes" type="button">${esc(t('ask_yes'))}</button>
       <button class="m-yn-btn no" type="button">${esc(t('ask_no'))}</button>`;
    msgs.appendChild(wrap);
    wrap.querySelector('.yes').addEventListener('click', () => { wrap.remove(); leadForm(); });
    wrap.querySelector('.no').addEventListener('click',  () => { wrap.remove(); botMsg(t('ask_declined')); });
    scroll(msgs);
  }

  /* ── Action button (donate / jobs / podcast / ...) ── */
  function actionButton(key) {
    let links = {};
    try { links = JSON.parse(S.action_links || '{}'); } catch {}
    const url = links[key];
    if (!url) return;

    const labels = {
      donate:    { he:'💝 למעבר לדף התרומות', en:'💝 Go to donations', ru:'💝 Перейти к пожертвованиям', ar:'💝 الانتقال إلى صفحة التبرعات' },
      jobs:      { he:'💼 לדף הדרושים',        en:'💼 View job openings', ru:'💼 Вакансии', ar:'💼 صفحة الوظائف' },
      podcast:   { he:'🎧 להאזנה לפודקאסט',     en:'🎧 Listen to the podcast', ru:'🎧 Слушать подкаст', ar:'🎧 الاستماع إلى البودكاست' },
      volunteer: { he:'🤝 להתנדבות',           en:'🤝 Volunteer', ru:'🤝 Стать волонтёром', ar:'🤝 التطوع' },
      contact:   { he:'📞 ליצירת קשר',         en:'📞 Contact us', ru:'📞 Связаться', ar:'📞 التواصل معنا' },
    };
    const label = (labels[key] || {})[lang] || (labels[key] || {}).he || key;

    const msgs = $id('m-msgs');
    const a = document.createElement('a');
    a.className = 'm-action-link';
    a.href = url;
    a.target = '_blank';
    a.rel = 'noopener';
    a.textContent = label;
    msgs.appendChild(a);
    scroll(msgs);
  }

  /* ── WooCommerce products ───────────────────────────────────────────────────
     The AI ends a reply with [PRODUCTS: 12,34]; the marker is stripped from the
     text (see sendText/vmAsk) and the ids are rendered here as rich cards. */

  const VISUAL_SEARCH  = (S.woo_visual_search ?? '0') === '1';
  const WOO_SHOW_STOCK = (S.woo_show_stock ?? '1') !== '0';
  const WOO_BUNDLE     = (S.woo_bundle_enabled ?? '1') !== '0';
  const MAX_IMG_BYTES  = 2 * 1024 * 1024;
  // Remembered from the last product carousel, so the bundle button can tell the AI
  // which category to complement.
  let lastShownCategory = '';

  // Live-agent handoff (two-way bridge): while active, the visitor talks to a real
  // agent (on WhatsApp) and the widget polls for the agent's replies.
  let humanMode = false, agentPollTimer = null, lastAgentId = 0;

  const WOO_T = {
    img_search:  'חיפוש מוצר לפי תמונה',
    searching:   'מחפש מוצרים דומים…',
    view:        'לצפייה במוצר',
    all_cat:     'לכל הקטגוריה',
    bundle_cta:  '✨ שאתאים לך מוצר משלים?',
    bundle_msg:  'אשמח שתמליץ לי על מוצר משלים שילך יחד עם מה שהצגת',
    variations:  'כמה אפשרויות זמינות',
    instock:     'במלאי',
    outofstock:  'אזל מהמלאי',
    onbackorder: 'בהזמנה מראש',
    too_big:     'התמונה גדולה מדי (עד 2MB). אפשר לנסות תמונה קטנה יותר.',
    ord_number:  'מספר הזמנה',
    ord_email:   'המייל שאיתו הזמנת',
    ord_check:   'בדיקת סטטוס',
    ord_checking:'בודק...',
    ord_none:    'לא מצאתי הזמנה שמתאימה למספר ולמייל שהוזנו. כדאי לבדוק שוב את מספר ההזמנה ואת המייל שאיתו בוצעה ההזמנה.',
    ord_err:     'לא הצלחתי לבדוק כרגע. אפשר לנסות שוב עוד רגע.',
    ord_status:  'סטטוס',
    ord_date:    'תאריך',
    ord_total:   'סכום',
    ord_items:   'פריטים',
    ord_track:   'מספר מעקב',
    ord_missing: 'צריך גם מספר הזמנה וגם מייל.',
  };

  /* "12, 34" → [12,34] — tolerant of spaces and stray separators */
  function parseIds(raw) {
    return String(raw || '').split(',').map(s => parseInt(s.trim(), 10)).filter(n => n > 0);
  }

  async function productCards(ids) {
    if (!ids || !ids.length || !$id('m-msgs')) return;
    try {
      const res = await fetch(CFG.apiUrl + '/products', {
        method: 'POST',
        headers: { 'Content-Type':'application/json', 'X-WP-Nonce': CFG.nonce },
        body: JSON.stringify({ ids }),
      });
      if (!res.ok) return;
      const d = await res.json();
      renderProducts(Array.isArray(d.products) ? d.products : []);
    } catch {}
  }

  /* Fetch complementary products for the AI's [SUGGEST: terms] and render them. */
  async function productSuggest(query) {
    if (!query || !$id('m-msgs')) return;
    try {
      const res = await fetch(CFG.apiUrl + '/product-query', {
        method: 'POST',
        headers: { 'Content-Type':'application/json', 'X-WP-Nonce': CFG.nonce },
        body: JSON.stringify({ query }),
      });
      if (!res.ok) return;
      const d = await res.json();
      // This IS the complementary result — no second bundle button on it.
      renderProducts(Array.isArray(d.products) ? d.products : [], { noBundle: true });
    } catch {}
  }

  function renderProducts(products, opts) {
    const msgs = $id('m-msgs');
    if (!msgs || !products.length) return;
    const allowBundle = !(opts && opts.noBundle);

    // Outer column: carousel → scroll dots → "view category" button.
    const box = document.createElement('div');
    box.className = 'm-products-box';
    box.style.cssText = 'flex:0 0 auto;align-self:stretch;width:100%;box-sizing:border-box;display:flex;flex-direction:column;gap:8px;margin:2px 0 4px';

    const wrap = document.createElement('div');
    wrap.className = 'm-products';
    // flex:0 0 auto is load-bearing: .m-msgs is a flex column, and this wrap's
    // overflow-x:auto makes it a scroll container whose auto min-height resolves to
    // 0 — so as a shrinkable column child it would collapse under vertical pressure,
    // squashing the cards to an image sliver (name/price/button clipped by the card's
    // overflow:hidden). Pinning flex-shrink:0 keeps the row at its natural height.
    // touch-action:pan-x is load-bearing on mobile — without it this horizontal scroller
    // swallows vertical finger-drags, so the visitor can't scroll the conversation up
    // when their finger lands on the carousel. pan-x keeps horizontal here, hands vertical
    // to the message list.
    wrap.style.cssText = 'flex:0 0 auto;display:flex;gap:10px;overflow-x:auto;overflow-y:hidden;touch-action:pan-x;padding:2px 2px 4px;scrollbar-width:thin;-webkit-overflow-scrolling:touch';
    wrap.innerHTML = products.map(productCard).join('');
    box.appendChild(wrap);

    // Scroll-position dots — only meaningful with more than one card.
    if (products.length > 1) {
      const dots = document.createElement('div');
      dots.style.cssText = 'display:flex;justify-content:center;gap:6px;padding:1px 0';
      dots.innerHTML = products.map((_, i) =>
        `<span data-i="${i}" style="width:6px;height:6px;border-radius:50%;background:var(--c-gray-300);transition:background .2s,transform .2s"></span>`
      ).join('');
      box.appendChild(dots);
      const dotEls = Array.from(dots.children);
      const paint = () => {
        const first = wrap.firstElementChild;
        if (!first) return;
        const step = first.getBoundingClientRect().width + 10; // card width + gap
        let idx = step > 0 ? Math.round(wrap.scrollLeft / step) : 0;
        idx = Math.max(0, Math.min(dotEls.length - 1, idx));
        dotEls.forEach((d, i) => {
          const on = i === idx;
          d.style.background = on ? 'var(--c-teal-600)' : 'var(--c-gray-300)';
          d.style.transform  = on ? 'scale(1.35)' : 'none';
        });
      };
      wrap.addEventListener('scroll', paint, { passive: true });
      paint();
    }

    // "View category" button — the category shared by the most products.
    const cat = dominantCategory(products);
    lastShownCategory = cat ? cat.name : '';
    if (cat) box.appendChild(categoryLink(cat));

    // Cross-sell: offer to match a complementary product (admin-toggle). Never on a
    // carousel that IS itself a complementary suggestion.
    if (WOO_BUNDLE && allowBundle && !chatEnded) box.appendChild(bundleButton());

    msgs.appendChild(box);
    scroll(msgs);
    // Images load async and grow the row after the first scroll — re-scroll each time
    // one lands, but ONLY if the visitor is still near the bottom. Otherwise an image
    // finishing while they scrolled up to read would yank them back down.
    wrap.querySelectorAll('img').forEach(img => {
      img.addEventListener('load', () => { if (nearBottom(msgs)) scroll(msgs); }, { once: true });
    });
  }

  /* The category most of these products share, as {name,url} (or null). */
  function dominantCategory(products) {
    const tally = {};
    products.forEach(p => (p.cat_links || []).forEach(c => {
      if (!c || !c.url) return;
      (tally[c.url] = tally[c.url] || { name: c.name, url: c.url, n: 0 }).n++;
    }));
    return Object.values(tally).sort((a, b) => b.n - a.n)[0] || null;
  }

  /* Outlined link to a category's archive page. */
  function categoryLink(cat) {
    const a = document.createElement('a');
    a.href = cat.url; a.target = '_blank'; a.rel = 'noopener';
    a.style.cssText = 'display:block;text-align:center;background:var(--c-teal-50);color:var(--c-teal-700);border:1.5px solid var(--c-teal-600);font-size:12.5px;font-weight:700;text-decoration:none;padding:9px 12px;border-radius:10px';
    a.textContent = `${WOO_T.all_cat} ${cat.name}`.trim();
    return a;
  }

  /* Cross-sell: asks the AI to match a complementary product. */
  function bundleButton() {
    const b = document.createElement('button');
    b.type = 'button';
    b.style.cssText = 'display:block;width:100%;text-align:center;background:var(--c-teal-600);color:#fff;border:0;font-size:12.5px;font-weight:700;padding:10px 12px;border-radius:10px;cursor:pointer';
    b.textContent = WOO_T.bundle_cta;
    b.addEventListener('click', () => {
      b.remove();
      const ctx = lastShownCategory ? `${WOO_T.bundle_msg} (${lastShownCategory})` : WOO_T.bundle_msg;
      sendText(ctx);
    }, { once: true });
    return b;
  }

  function productCard(p) {
    const badge = WOO_SHOW_STOCK ? stockBadge(p.stock_status) : '';
    const img = p.image
      ? `<img src="${esc(p.image)}" alt="${esc(p.name)}" loading="lazy"
             style="width:100%;height:118px;object-fit:cover;background:#f2f4f6">`
      : `<div style="width:100%;height:118px;background:#f2f4f6"></div>`;
    const variations = (p.variations && p.variations.length)
      ? `<div style="font-size:11px;opacity:.65">${WOO_T.variations}</div>` : '';

    // price_html is WooCommerce-formatted markup (currency + sale strikethrough) — render as-is
    return `
      <div class="m-product" style="flex:0 0 168px;display:flex;flex-direction:column;border:1px solid rgba(0,0,0,.09);border-radius:12px;overflow:hidden;background:#fff">
        <div style="position:relative">
          ${img}
          ${p.on_sale ? `<span style="position:absolute;top:6px;inset-inline-start:6px;background:#e0245e;color:#fff;font-size:10px;font-weight:700;padding:2px 6px;border-radius:6px">SALE</span>` : ''}
        </div>
        <div style="display:flex;flex-direction:column;gap:5px;padding:8px 9px 10px;flex:1">
          <div style="font-size:12.5px;font-weight:600;line-height:1.35;max-height:2.7em;overflow:hidden">${esc(p.name)}</div>
          <div style="font-size:12.5px;color:var(--c-teal-600);font-weight:700">${p.price_html || ''}</div>
          ${badge}
          ${variations}
          <a href="${esc(p.permalink)}" target="_blank" rel="noopener"
             style="margin-top:auto;display:block;text-align:center;background:var(--c-teal-600);color:#fff;font-size:12px;font-weight:600;text-decoration:none;padding:7px 8px;border-radius:8px">${WOO_T.view}</a>
        </div>
      </div>`;
  }

  function stockBadge(status) {
    const on = status === 'instock';
    const label = status === 'onbackorder' ? WOO_T.onbackorder : (on ? WOO_T.instock : WOO_T.outofstock);
    const color = on ? '#0a7a34' : status === 'onbackorder' ? '#8a5a00' : '#b3261e';
    const bg    = on ? 'rgba(10,122,52,.10)' : status === 'onbackorder' ? 'rgba(138,90,0,.10)' : 'rgba(179,38,30,.10)';
    return `<span style="align-self:flex-start;font-size:10.5px;font-weight:700;color:${color};background:${bg};padding:2px 7px;border-radius:20px">${label}</span>`;
  }

  /* ─ Human handoff: click-to-WhatsApp button to a live agent ─ */
  function handoffButton(url, label) {
    const msgs = $id('m-msgs');
    if (!msgs || !url) return;
    const a = document.createElement('a');
    a.href = url; a.target = '_blank'; a.rel = 'noopener';
    a.style.cssText = 'display:flex;align-items:center;justify-content:center;gap:9px;margin:4px 0 6px;background:#25a35a;color:#fff;font-size:13.5px;font-weight:700;text-decoration:none;padding:12px 16px;border-radius:12px;box-shadow:0 8px 18px -8px rgba(37,163,90,.7)';
    a.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="#fff"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.9c0 1.76.46 3.47 1.34 4.98L2 22l5.25-1.38a9.9 9.9 0 004.78 1.22h.01c5.46 0 9.9-4.44 9.9-9.9 0-2.65-1.03-5.14-2.9-7.01A9.8 9.8 0 0012.05 2zm5.8 14.16c-.24.68-1.4 1.3-1.94 1.38-.5.07-1.12.1-1.8-.11-.42-.13-.95-.31-1.64-.61-2.88-1.24-4.76-4.14-4.9-4.33-.14-.19-1.17-1.56-1.17-2.98 0-1.42.74-2.12 1.01-2.41.27-.29.58-.36.77-.36l.56.01c.18 0 .42-.07.66.5.24.58.82 2 .89 2.15.07.14.12.31.02.5-.09.19-.14.31-.28.48l-.42.5c-.14.14-.28.29-.12.56.16.27.71 1.17 1.53 1.9 1.05.93 1.94 1.22 2.21 1.36.27.14.43.12.59-.07.16-.19.68-.79.86-1.06.18-.27.36-.22.61-.13.25.09 1.58.74 1.85.88.27.13.45.2.52.31.07.11.07.64-.17 1.32z"/></svg><span>' + esc(label || 'המשך בוואטסאפ') + '</span>';
    msgs.appendChild(a);
    scroll(msgs);
  }

  /* ─ Live-agent bridge: visitor stays here, a real agent answers from WhatsApp ─ */
  function enterHumanMode() {
    if (humanMode) return;
    humanMode = true;
    systemNote('🟢 מחובר/ת לנציג/ה. מכאן ההודעות שלך מגיעות ישירות אליו/ה.');
    startAgentPoll();
  }
  function exitHumanMode() {
    if (!humanMode) return;
    humanMode = false;
    stopAgentPoll();
    systemNote('השיחה עם הנציג/ה הסתיימה. אני שוב כאן לכל שאלה.');
  }
  function stopAgentPoll() { if (agentPollTimer) { clearInterval(agentPollTimer); agentPollTimer = null; } }
  function startAgentPoll() {
    stopAgentPoll();
    pollAgent();
    agentPollTimer = setInterval(pollAgent, 4000);
  }
  async function pollAgent() {
    if (!humanMode) { stopAgentPoll(); return; }
    try {
      const res = await fetch(CFG.apiUrl + '/agent-poll?session_id=' + encodeURIComponent(sid) + '&after=' + lastAgentId, {
        headers: { 'X-WP-Nonce': CFG.nonce },
      });
      if (!res.ok) return;
      const d = await res.json();
      (d.messages || []).forEach(m => {
        if (m.id > lastAgentId) lastAgentId = m.id;
        if (m.role === 'agent') agentMsg(m.content);
        else systemNote(m.content);
      });
      if (d.human === false) exitHumanMode();
    } catch {}
  }
  /* A message from the human agent — styled distinctly from the bot. */
  function agentMsg(text) {
    const msgs = $id('m-msgs');
    if (!msgs) return;
    const el = document.createElement('div');
    el.className = 'm-msg bot';
    el.innerHTML = `<div class="m-bub" style="border:1px solid rgba(37,163,90,.4)">${fmt(text)}</div>
      <div class="m-ts"><span style="color:#25a35a;font-weight:700">● נציג/ה</span> · ${clock()}</div>`;
    msgs.appendChild(el);
    scroll(msgs);
    speak(text);
  }

  /* ─ Order status: order # + email → secure status lookup ─ */
  function orderStatusForm() {
    const msgs = $id('m-msgs');
    if (!msgs) return;
    const wrap = document.createElement('div');
    wrap.style.cssText = 'display:flex;flex-direction:column;gap:8px;background:#fff;border:1px solid rgba(0,0,0,.09);border-radius:12px;padding:12px;margin:2px 0 4px';
    const inp = 'width:100%;box-sizing:border-box;padding:9px 11px;border:1.5px solid var(--c-gray-200);border-radius:9px;font-family:var(--font);font-size:13px';
    wrap.innerHTML =
      `<input type="text"  class="mo-num"  inputmode="numeric" placeholder="${esc(WOO_T.ord_number)}" style="${inp};direction:rtl">
       <input type="email" class="mo-mail" dir="ltr" placeholder="${esc(WOO_T.ord_email)}" style="${inp}">
       <button type="button" class="mo-go" style="background:var(--c-teal-600);color:#fff;border:0;font-size:13px;font-weight:700;padding:10px;border-radius:9px;cursor:pointer">${esc(WOO_T.ord_check)}</button>`;
    msgs.appendChild(wrap);
    scroll(msgs);

    const numEl = wrap.querySelector('.mo-num');
    const mailEl = wrap.querySelector('.mo-mail');
    const btn = wrap.querySelector('.mo-go');
    btn.addEventListener('click', async () => {
      const order_id = numEl.value.trim(), email = mailEl.value.trim();
      numEl.style.borderColor  = order_id ? '' : '#e0245e';
      mailEl.style.borderColor = email ? '' : '#e0245e';
      if (!order_id || !email) return;
      btn.disabled = true; btn.textContent = WOO_T.ord_checking;
      try {
        const res = await fetch(CFG.apiUrl + '/order-status', {
          method: 'POST',
          headers: { 'Content-Type':'application/json', 'X-WP-Nonce': CFG.nonce },
          body: JSON.stringify({ order_id, email }),
        });
        const d = res.ok ? await res.json() : null;
        wrap.remove();
        if (!d) { botMsg(WOO_T.ord_err); return; }
        if (!d.found) { botMsg(WOO_T.ord_none); return; }
        renderOrderStatus(d.order);
      } catch {
        btn.disabled = false; btn.textContent = WOO_T.ord_check;
        botMsg(WOO_T.ord_err);
      }
    });
  }

  function renderOrderStatus(o) {
    const msgs = $id('m-msgs');
    if (!msgs || !o) return;
    const items = (o.items || []).map(it =>
      `<div style="font-size:12px;opacity:.8">• ${esc(it.name)}${it.qty > 1 ? ' ×' + it.qty : ''}</div>`).join('');
    const track = (o.tracking || []).map(t => {
      const label = `${esc(WOO_T.ord_track)}: ${esc(t.number)}${t.provider ? ' (' + esc(t.provider) + ')' : ''}`;
      return t.url
        ? `<a href="${esc(t.url)}" target="_blank" rel="noopener" style="font-size:12px;color:var(--c-teal-600);font-weight:700;text-decoration:none">${label}</a>`
        : `<div style="font-size:12px;font-weight:700">${label}</div>`;
    }).join('');
    const card = document.createElement('div');
    card.style.cssText = 'display:flex;flex-direction:column;gap:6px;background:#fff;border:1px solid rgba(0,0,0,.09);border-radius:12px;padding:12px;margin:2px 0 4px';
    card.innerHTML =
      `<div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
         <span style="font-weight:700;font-size:13px">${esc(WOO_T.ord_number)} ${esc(o.number)}</span>
         <span style="background:var(--c-teal-50);color:var(--c-teal-700);font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:20px;white-space:nowrap">${esc(o.status_label)}</span>
       </div>
       ${o.date ? `<div style="font-size:12px;opacity:.75">${esc(WOO_T.ord_date)}: ${esc(o.date)}</div>` : ''}
       ${items ? `<div style="border-top:1px solid rgba(0,0,0,.06);padding-top:6px">${items}</div>` : ''}
       ${o.total ? `<div style="font-size:12.5px;font-weight:700;color:var(--c-teal-700)">${esc(WOO_T.ord_total)}: ${esc(o.total)}</div>` : ''}
       ${track ? `<div style="border-top:1px solid rgba(0,0,0,.06);padding-top:6px">${track}</div>` : ''}`;
    msgs.appendChild(card);
    scroll(msgs);
  }

  /* ─ Visual search: pick an image → find lookalike products ─ */
  async function imageSearch(file) {
    if (chatEnded) return;
    if (file.size > MAX_IMG_BYTES) { botMsg(WOO_T.too_big); return; }

    clearSuggestions();
    hadActivity = true;
    resetIdle();

    let dataUrl;
    try { dataUrl = await fileToDataUrl(file); } catch { botMsg(t('error')); return; }

    userImg(dataUrl);
    const dots = typingNote(WOO_T.searching);

    try {
      const res = await fetch(CFG.apiUrl + '/product-image-search', {
        method: 'POST',
        headers: { 'Content-Type':'application/json', 'X-WP-Nonce': CFG.nonce },
        body: JSON.stringify({ image: dataUrl, lang }),
      });
      dots.remove();
      if (!res.ok) throw new Error(res.status);
      const d = await res.json();
      if (d.reply) botMsg(d.reply);
      renderProducts(Array.isArray(d.products) ? d.products : []);
    } catch {
      dots.remove();
      botMsg(t('error'));
    }
  }

  function fileToDataUrl(file) {
    return new Promise((resolve, reject) => {
      const r = new FileReader();
      r.onloadend = () => resolve(String(r.result || ''));
      r.onerror = reject;
      r.readAsDataURL(file);
    });
  }

  /* The visitor's uploaded image, shown as their "message" */
  function userImg(src) {
    const msgs = $id('m-msgs');
    if (!msgs) return;
    const el = document.createElement('div');
    el.className = 'm-msg user';
    el.innerHTML = `
      <div class="m-bub" style="padding:5px">
        <img src="${esc(src)}" alt="" style="display:block;max-width:150px;border-radius:8px">
      </div>
      <div class="m-ts">${clock()}</div>`;
    msgs.appendChild(el);
    scroll(msgs);
  }

  /* Typing dots with a label ("searching…") — same bubble as typing() */
  function typingNote(label) {
    const msgs = $id('m-msgs');
    const el = document.createElement('div');
    el.className = 'm-msg bot';
    el.innerHTML = `<div class="m-bub" style="padding:6px 14px;display:flex;align-items:center;gap:8px">
        <div class="m-typing"><span></span><span></span><span></span></div>
        <span style="font-size:12px;opacity:.7">${esc(label)}</span>
      </div>`;
    msgs.appendChild(el);
    scroll(msgs);
    return el;
  }

  /* ── Voice ──────────────────────────────────────────────────────────────────
     Two engines, chosen by the admin setting `voice_provider`:
       • 'openai'  — record mic audio → Whisper (transcribe) ; reply → OpenAI TTS (natural voice)
       • 'browser' — free Web Speech API (webkitSpeechRecognition + speechSynthesis)
     ChatGPT-style: press mic once, speak, auto-stops on silence, auto-sends, speaks the answer. */

  const VOICE_ON      = (S.voice_enabled ?? '1') !== '0';
  const VOICE_PROVIDER = S.voice_provider || 'openai';
  const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  const HAS_RECORDER  = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
  const HAS_RTC       = !!(window.RTCPeerConnection && navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
  const REALTIME_ON   = (S.realtime_enabled ?? '1') !== '0';
  const VOICE_TEXT_MODE = S.voice_text_mode || 'none';   // none | end | live
  // Prefer true real-time speech-to-speech (OpenAI Realtime API over WebRTC)
  const USE_REALTIME   = VOICE_PROVIDER === 'openai' && REALTIME_ON && HAS_RTC;
  // Use OpenAI voice when configured AND the browser can record; otherwise fall back to Web Speech
  const USE_OPENAI_STT = VOICE_PROVIDER === 'openai' && HAS_RECORDER;
  const USE_OPENAI_TTS = VOICE_PROVIDER === 'openai';
  const MIC_AVAILABLE  = USE_REALTIME || USE_OPENAI_STT || !!SR;
  const TTS_AVAILABLE  = USE_OPENAI_TTS || ('speechSynthesis' in window);

  // Shared orb state, used by both the realtime and chained engines
  let voiceState = 'idle';

  /* ─ Mobile audio unlock ─
     Mobile browsers (iOS Safari especially) block audio that isn't started by a
     direct user gesture. We unlock ONE shared <audio> element + AudioContext on the
     mic tap, then reuse them for every reply so playback is allowed afterwards. */
  const SILENT_AUDIO = 'data:audio/wav;base64,UklGRgQCAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YeABAACAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIA=';
  let AC = null;            // shared AudioContext (for the listening orb analyser)
  let playEl = null;        // shared, unlocked playback element (TTS + realtime audio)
  let micAnalyser = null;   // mic amplitude for the listening orb

  /* Create the playback element ONCE and attach it to the document — iOS Safari
     will NOT play a detached `new Audio()` element, so it must live in the DOM. */
  function ensurePlayEl() {
    if (playEl) return playEl;
    try {
      playEl = document.createElement('audio');
      playEl.setAttribute('playsinline', '');
      playEl.playsInline = true;
      playEl.preload = 'auto';
      playEl.style.cssText = 'position:absolute;width:0;height:0;opacity:0;pointer-events:none';
      ( document.body || document.documentElement ).appendChild( playEl );
    } catch {}
    return playEl;
  }

  function unlockAudio() {
    try {
      if (!AC) AC = new (window.AudioContext || window.webkitAudioContext)();
      if (AC.state === 'suspended' && AC.resume) AC.resume();
    } catch {}
    try {
      ensurePlayEl();
      playEl.muted = false;
      playEl.src = SILENT_AUDIO;
      const p = playEl.play();
      if (p && p.catch) p.catch(() => {});
    } catch {}
  }

  function playAudioData(dataUrl) {
    ensurePlayEl();
    try {
      playEl.srcObject = null;
      playEl.src = dataUrl;
      const p = playEl.play();
      if (p && p.catch) p.catch(() => {});
    } catch {}
  }

  function stopPlayEl() {
    if (!playEl) return;
    try { playEl.pause(); } catch {}
    try { playEl.srcObject = null; } catch {}
    try { playEl.removeAttribute('src'); playEl.load(); } catch {}
  }

  let recog = null;          // browser SpeechRecognition instance
  let listening = false;     // mic actively capturing
  let mediaRecorder = null;  // MediaRecorder (OpenAI mode)
  let mediaStream = null;
  let audioChunks = [];
  let audioCtx = null;
  let speakMode = sessionStorage.getItem('wisply_speak') === '1';

  const localeFor = l => l === 'en' ? 'en-US' : l === 'ru' ? 'ru-RU' : l === 'ar' ? 'ar-SA' : 'he-IL';

  function toggleListen() {
    if (!MIC_AVAILABLE) return;
    if (listening) { stopListen(); return; }
    // Entering a voice turn → enable spoken replies, and silence any current playback
    if (!speakMode) { speakMode = true; sessionStorage.setItem('wisply_speak','1'); updateSpeakerUI(); }
    stopAudio();
    USE_OPENAI_STT ? startRecording() : startBrowserSTT();
  }

  function stopListen() {
    listening = false;
    const mic = $id('m-mic');
    if (mic) mic.classList.remove('listening');
    // Browser path
    if (recog) { try { recog.stop(); } catch {} recog = null; }
    // OpenAI path
    if (mediaRecorder && mediaRecorder.state !== 'inactive') { try { mediaRecorder.stop(); } catch {} }
  }

  /* ─ OpenAI STT: record → Whisper ─ */
  async function startRecording() {
    let stream;
    try { stream = await navigator.mediaDevices.getUserMedia({ audio: true }); }
    catch { systemNote(micDeniedMsg()); return; }

    mediaStream = stream;
    audioChunks = [];
    const mime = ['audio/webm;codecs=opus','audio/webm','audio/mp4','audio/ogg']
      .find(m => window.MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(m)) || '';
    try { mediaRecorder = mime ? new MediaRecorder(stream, { mimeType: mime }) : new MediaRecorder(stream); }
    catch { stopTracks(); return; }

    mediaRecorder.ondataavailable = e => { if (e.data && e.data.size) audioChunks.push(e.data); };
    mediaRecorder.onstop = () => {
      stopTracks();
      const blob = new Blob(audioChunks, { type: mediaRecorder.mimeType || 'audio/webm' });
      if (blob.size > 1200) transcribeBlob(blob);   // ignore empty/too-short clips
    };

    listening = true;
    $id('m-mic').classList.add('listening');
    mediaRecorder.start();
    monitorSilence(stream);                          // auto-stop when the user finishes speaking
    // Hard cap: never record longer than 30s
    setTimeout(() => { if (listening) stopListen(); }, 30000);
  }

  // Auto-stop recording after a short pause once the user has spoken (ChatGPT-style)
  function monitorSilence(stream) {
    try {
      audioCtx = new (window.AudioContext || window.webkitAudioContext)();
      const src = audioCtx.createMediaStreamSource(stream);
      const analyser = audioCtx.createAnalyser();
      analyser.fftSize = 512;
      src.connect(analyser);
      const data = new Uint8Array(analyser.fftSize);
      let spoke = false, silenceStart = null;
      const SILENCE = 0.012, HANG_MS = 1300;
      (function tick() {
        if (!listening) { closeAudioCtx(); return; }
        analyser.getByteTimeDomainData(data);
        let sum = 0;
        for (let i = 0; i < data.length; i++) { const v = (data[i] - 128) / 128; sum += v * v; }
        const rms = Math.sqrt(sum / data.length);
        const now = (window.performance && performance.now) ? performance.now() : Date.now();
        if (rms > SILENCE) { spoke = true; silenceStart = null; }
        else if (spoke) {
          if (silenceStart === null) silenceStart = now;
          else if (now - silenceStart > HANG_MS) { closeAudioCtx(); stopListen(); return; }
        }
        requestAnimationFrame(tick);
      })();
    } catch {}
  }

  async function transcribeBlob(blob) {
    const mic = $id('m-mic');
    if (mic) mic.classList.add('processing');
    try {
      const b64 = await blobToBase64(blob);
      const res = await fetch(CFG.apiUrl + '/voice/transcribe', {
        method: 'POST',
        headers: { 'Content-Type':'application/json', 'X-WP-Nonce': CFG.nonce },
        body: JSON.stringify({ audio: b64, mime: blob.type, lang }),
      });
      const d = await res.json();
      if (mic) mic.classList.remove('processing');
      if (res.ok && d.text && d.text.trim()) sendText(d.text.trim());
    } catch {
      if (mic) mic.classList.remove('processing');
    }
  }

  /* ─ Browser STT (free fallback) ─ */
  function startBrowserSTT() {
    try { recog = new SR(); } catch { return; }
    recog.lang = localeFor(lang);
    recog.interimResults = true;
    recog.continuous = false;
    recog.maxAlternatives = 1;

    const ta = $id('m-textarea');
    listening = true;
    $id('m-mic').classList.add('listening');
    if ('speechSynthesis' in window) speechSynthesis.cancel();

    recog.onresult = e => {
      let txt = '';
      for (let i = 0; i < e.results.length; i++) txt += e.results[i][0].transcript;
      if (ta) { ta.value = txt; autoH(ta); }
      const snd = $id('m-send'); if (snd) snd.disabled = !txt.trim();
      if (e.results[e.results.length - 1].isFinal) {
        stopListen();
        if (txt.trim()) setTimeout(() => sendText(txt.trim()), 150);
      }
    };
    recog.onerror = () => stopListen();
    recog.onend   = () => stopListen();
    try { recog.start(); } catch { stopListen(); }
  }

  /* ─ Speaker toggle + speak ─ */
  function toggleSpeak() {
    speakMode = !speakMode;
    sessionStorage.setItem('wisply_speak', speakMode ? '1' : '0');
    if (speakMode) unlockAudio();   // unlock within the tap so mobile allows TTS playback
    else stopAudio();
    updateSpeakerUI();
  }

  function updateSpeakerUI() {
    const spk = $id('m-speaker');
    if (!spk) return;
    spk.innerHTML = speakMode ? ICO_SPEAKER_ON : ICO_SPEAKER_OFF;
    spk.classList.toggle('on', speakMode);
  }

  function speak(text) {
    if (!speakMode || !text) return;
    USE_OPENAI_TTS ? speakOpenAI(text) : speakBrowser(text);
  }

  async function speakOpenAI(text) {
    stopAudio();
    try {
      const res = await fetch(CFG.apiUrl + '/voice/speak', {
        method: 'POST',
        headers: { 'Content-Type':'application/json', 'X-WP-Nonce': CFG.nonce },
        body: JSON.stringify({ text, lang }),
      });
      if (!res.ok) return;
      const d = await res.json();
      if (!d.audio) return;
      if (playEl) playEl.onended = null;
      playAudioData('data:' + (d.mime || 'audio/mpeg') + ';base64,' + d.audio);
    } catch {}
  }

  function speakBrowser(text) {
    if (!('speechSynthesis' in window)) return;
    speechSynthesis.cancel();
    const u = new SpeechSynthesisUtterance(text.replace(/[*_#>`]/g, ''));
    u.lang = localeFor(lang);
    u.rate = 1; u.pitch = 1;
    const v = (speechSynthesis.getVoices() || []).find(v => v.lang && v.lang.toLowerCase().startsWith(lang));
    if (v) u.voice = v;
    speechSynthesis.speak(u);
  }

  function stopAudio() {
    stopPlayEl();
    if ('speechSynthesis' in window) speechSynthesis.cancel();
  }

  /* ─ Voice utilities ─ */
  function blobToBase64(blob) {
    return new Promise((resolve, reject) => {
      const r = new FileReader();
      r.onloadend = () => resolve(String(r.result).split(',')[1] || '');
      r.onerror = reject;
      r.readAsDataURL(blob);
    });
  }
  function stopTracks() { if (mediaStream) { mediaStream.getTracks().forEach(t => t.stop()); mediaStream = null; } }
  function closeAudioCtx() { if (audioCtx) { try { audioCtx.close(); } catch {} audioCtx = null; } }
  function micDeniedMsg() {
    return { he:'🎤 לא ניתנה הרשאה למיקרופון. אפשר להפעיל אותה בהגדרות הדפדפן.',
             en:'🎤 Microphone permission was denied. You can enable it in your browser settings.',
             ru:'🎤 Доступ к микрофону запрещён. Включите его в настройках браузера.',
             ar:'🎤 لم يتم منح إذن الميكروفون. يمكنك تفعيله من إعدادات المتصفح.' }[lang] || '';
  }

  /* ── Immersive Voice Mode (ChatGPT-style hands-free call) ───────────────────
     A dedicated overlay with an audio-reactive orb. The loop runs itself:
       LISTEN (mic, auto-stop on silence) → THINK (Whisper → /chat) → SPEAK (TTS) → LISTEN …
     No text is shown — it's a voice conversation. Tap the orb while it speaks to
     interrupt and talk over it. Conversations are still saved server-side via /chat. */
  const VM = {
    active:false, state:'idle', muted:false, discard:false,
    stream:null, ctx:null, micAnalyser:null, playAnalyser:null,
    recorder:null, chunks:[], audio:null, raf:null,
    spoke:false, silenceStart:null, recStart:0,
  };
  const vmNow = () => (window.performance && performance.now) ? performance.now() : Date.now();

  /* Mount the shared voice overlay (orb + status + controls). Returns the element. */
  function vmMountOverlay(handlers) {
    const win = $id('m-win');
    if (!win) return null;
    const ov = document.createElement('div');
    ov.className = 'm-vm';
    ov.id = 'm-vm';
    ov.setAttribute('data-state', 'idle');
    ov.innerHTML = `
      <button class="m-vm-close" id="m-vm-x" aria-label="${t('vm_end')}">✕</button>
      <div class="m-orb-wrap" id="m-orb-wrap" role="img" aria-label="${t('voice_call')}">
        <div class="m-orb" id="m-orb"></div>
      </div>
      <div class="m-vm-status" id="m-vm-status" aria-live="polite">${t('vm_connecting')}</div>
      <div class="m-vm-hint">${t('vm_hint')}</div>
      <div class="m-vm-transcript" id="m-vm-transcript"${VOICE_TEXT_MODE === 'live' ? '' : ' hidden'}></div>
      <div class="m-vm-controls">
        <button class="m-vm-btn" id="m-vm-mute" aria-label="${t('vm_mute')}">${ICO_MIC}</button>
        <button class="m-vm-btn m-vm-end" id="m-vm-end" aria-label="${t('vm_end')}">${ICO_PHONE_DOWN}</button>
      </div>`;
    win.appendChild(ov);
    $id('m-vm-x').addEventListener('click', handlers.close);
    $id('m-vm-end').addEventListener('click', handlers.close);
    $id('m-vm-mute').addEventListener('click', handlers.mute);
    $id('m-orb-wrap').addEventListener('click', handlers.tap);
    return ov;
  }

  function vmSetState(s) { voiceState = s; $id('m-vm')?.setAttribute('data-state', s); }
  function vmStatus(txt) { const el = $id('m-vm-status'); if (el) el.textContent = txt; }

  /* ─ Voice transcript (admin setting voice_text_mode: none | end | live) ─ */
  let voiceTurns = [];   // accumulates {role,text} for the 'end' mode flush

  function vmResetTranscript() { voiceTurns = []; }

  function vmAddTranscript(role, text) {
    text = (text || '').trim();
    if (!text || VOICE_TEXT_MODE === 'none') return;
    if (VOICE_TEXT_MODE === 'live') {
      role === 'user' ? userMsg(text) : botMsg(text);   // write into the chat log immediately
      vmCaption(role, text);                             // and show inside the call overlay
    } else {
      voiceTurns.push({ role, text });                  // 'end' → hold until hang-up
    }
  }

  function vmFlushTranscript() {
    if (VOICE_TEXT_MODE === 'end' && voiceTurns.length) {
      voiceTurns.forEach(turn => turn.role === 'user' ? userMsg(turn.text) : botMsg(turn.text));
    }
    voiceTurns = [];
  }

  function vmCaption(role, text) {
    const el = $id('m-vm-transcript');
    if (!el) return;
    const line = document.createElement('div');
    line.className = 'm-vm-line ' + (role === 'user' ? 'user' : 'bot');
    line.textContent = text;
    el.appendChild(line);
    el.scrollTop = el.scrollHeight;
  }

  /* Dispatcher: real-time speech-to-speech if available, else the chained pipeline. */
  function openVoiceMode() {
    if (USE_REALTIME) return openRealtimeMode();
    if (HAS_RECORDER) return openChainedVoiceMode();
    return toggleListen();
  }

  /* Real-time failed/stalled for this call → switch to the reliable chained pipeline.
     We do NOT permanently disable real-time, so the next call tries it again. */
  function realtimeFallback() {
    closeRealtime();
    openChainedVoiceMode();
  }

  /* End whichever voice engine is currently running. */
  function endVoice() {
    if (RT.active) closeRealtime();
    if (VM.active) closeVoiceMode();
  }

  async function openChainedVoiceMode() {
    if (VM.active) return;
    if (!vmMountOverlay({ close: closeVoiceMode, mute: vmToggleMute, tap: vmOrbTap })) return;
    vmResetTranscript();

    // Acquire the mic once and reuse it for the whole call
    try {
      VM.stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch {
      vmStatus(micDeniedMsg());
      setTimeout(closeVoiceMode, 2600);
      return;
    }
    setupMicAnalyser(VM.stream);

    VM.active = true;
    hadActivity = true; resetIdle();
    vmAnimate();
    vmStartListen();
  }

  /* Build the shared mic analyser (listening orb) on the shared AudioContext */
  function setupMicAnalyser(stream) {
    try {
      if (!AC) AC = new (window.AudioContext || window.webkitAudioContext)();
      if (AC.state === 'suspended' && AC.resume) AC.resume();
      const micSrc = AC.createMediaStreamSource(stream);
      micAnalyser = AC.createAnalyser();
      micAnalyser.fftSize = 512;
      micSrc.connect(micAnalyser);
    } catch { micAnalyser = null; }
  }

  function closeVoiceMode() {
    if (!VM.active && !$id('m-vm')) return;
    VM.active = false;
    vmFlushTranscript();
    if (VM.raf) cancelAnimationFrame(VM.raf);
    if (VM.recorder && VM.recorder.state !== 'inactive') { try { VM.recorder.stop(); } catch {} }
    stopPlayEl();
    if (VM.stream) { VM.stream.getTracks().forEach(t => t.stop()); }
    VM.stream = VM.recorder = VM.audio = VM.raf = null;
    micAnalyser = null;
    VM.muted = false; voiceState = 'idle';
    $id('m-vm')?.remove();
  }

  /* LISTEN — record until the user pauses, then transcribe */
  function vmStartListen() {
    if (!VM.active || VM.muted) return;
    VM.chunks = []; VM.spoke = false; VM.silenceStart = null; VM.discard = false;
    const mime = ['audio/webm;codecs=opus','audio/webm','audio/mp4','audio/ogg']
      .find(m => window.MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(m)) || '';
    try { VM.recorder = mime ? new MediaRecorder(VM.stream, { mimeType: mime }) : new MediaRecorder(VM.stream); }
    catch { return; }

    VM.recorder.ondataavailable = e => { if (e.data && e.data.size) VM.chunks.push(e.data); };
    VM.recorder.onstop = () => {
      if (!VM.active) return;
      const blob = new Blob(VM.chunks, { type: VM.recorder.mimeType || 'audio/webm' });
      if (VM.discard || blob.size < 1400) { vmStartListen(); return; }   // nothing meaningful said
      vmTranscribe(blob);
    };

    VM.recStart = vmNow();
    VM.recorder.start();
    vmSetState('listen');
    vmStatus(t('vm_listen'));
  }

  function vmStopListen(discard) {
    VM.discard = !!discard;
    if (VM.recorder && VM.recorder.state !== 'inactive') { try { VM.recorder.stop(); } catch {} }
  }

  /* THINK — Whisper transcription */
  async function vmTranscribe(blob) {
    vmSetState('think'); vmStatus(t('vm_think'));
    try {
      const b64 = await blobToBase64(blob);
      const res = await fetch(CFG.apiUrl + '/voice/transcribe', {
        method: 'POST',
        headers: { 'Content-Type':'application/json', 'X-WP-Nonce': CFG.nonce },
        body: JSON.stringify({ audio: b64, mime: blob.type, lang }),
      });
      const d = await res.json();
      if (!VM.active) return;
      const text = (d.text || '').trim();
      if (!text) { vmStartListen(); return; }      // didn't catch anything → keep listening
      vmAddTranscript('user', text);
      vmAsk(text);
    } catch {
      if (VM.active) { vmStatus(t('vm_error')); setTimeout(() => VM.active && vmStartListen(), 1200); }
    }
  }

  /* THINK — content-grounded answer via the same /chat endpoint (RAG preserved) */
  async function vmAsk(text) {
    vmSetState('think'); vmStatus(t('vm_think'));
    hadActivity = true; resetIdle();
    try {
      const res = await fetch(CFG.apiUrl + '/chat', {
        method: 'POST',
        headers: { 'Content-Type':'application/json', 'X-WP-Nonce': CFG.nonce },
        body: JSON.stringify({ message: text, session_id: sid, lang, page_url: location.href }),
      });
      const d = await res.json();
      if (!VM.active) return;
      let reply = (d.reply || '')
        .replace(/\[ACTION:[a-z_]+\]/ig, '')
        .replace(/\[OPTIONS:[^\]]+\]/ig, '')
        .replace(/\[PRODUCTS:[^\]]*\]/ig, '')
        .replace(/\[ASK_LEAD\]/ig, '')
        .replace(/\[SHOW_LEAD_FORM\]/ig, '')
        .replace(/\[EMERGENCY\]/ig, '')
        .trim();
      if (!reply) { vmStartListen(); return; }
      vmAddTranscript('bot', reply);
      vmSpeak(reply);
    } catch {
      if (VM.active) { vmStatus(t('vm_error')); setTimeout(() => VM.active && vmStartListen(), 1200); }
    }
  }

  /* SPEAK — natural OpenAI voice via the unlocked shared element, then auto-listen */
  async function vmSpeak(text) {
    vmStatus(t('vm_speak'));
    try {
      const res = await fetch(CFG.apiUrl + '/voice/speak', {
        method: 'POST',
        headers: { 'Content-Type':'application/json', 'X-WP-Nonce': CFG.nonce },
        body: JSON.stringify({ text, lang }),
      });
      const d = await res.json();
      if (!VM.active) return;
      if (!d.audio) { vmStartListen(); return; }

      vmSetState('speak'); vmStatus(t('vm_speak'));
      if (playEl) {
        playEl.onended = () => { if (VM.active) vmStartListen(); };
      }
      playAudioData('data:' + (d.mime || 'audio/mpeg') + ';base64,' + d.audio);
    } catch {
      if (VM.active) vmStartListen();
    }
  }

  /* Tap the orb while it's speaking → interrupt and listen (barge-in) */
  function vmOrbTap() {
    if (voiceState === 'speak') {
      if (playEl) playEl.onended = null;
      stopPlayEl();
      vmStartListen();
    }
  }

  function vmToggleMute() {
    VM.muted = !VM.muted;
    const btn = $id('m-vm-mute');
    if (btn) { btn.classList.toggle('muted', VM.muted); btn.innerHTML = VM.muted ? ICO_MIC_OFF : ICO_MIC; }
    if (VM.muted) {
      vmStopListen(true);
      vmSetState('idle'); vmStatus(t('vm_muted'));
    } else if (voiceState !== 'speak') {
      vmStartListen();
    }
  }

  /* One rAF loop drives the orb amplitude and the listen-silence detection */
  function vmAnimate() {
    const wrap = $id('m-orb-wrap');
    const buf  = new Uint8Array(512);
    const tick = () => {
      if (!VM.active) return;
      let amp = 0;
      if (voiceState === 'listen' && micAnalyser) {
        micAnalyser.getByteTimeDomainData(buf);
        let sum = 0;
        for (let i = 0; i < buf.length; i++) { const v = (buf[i] - 128) / 128; sum += v * v; }
        const rms = Math.sqrt(sum / buf.length);
        amp = Math.min(1, rms * 3.4);

        const now = vmNow();
        if (rms > 0.018) { VM.spoke = true; VM.silenceStart = null; }
        else if (VM.spoke) {
          if (VM.silenceStart === null) VM.silenceStart = now;
          else if (now - VM.silenceStart > 1200) { vmStopListen(false); }
        }
        if (now - VM.recStart > 30000) vmStopListen(false);   // hard cap 30s
      } else if (voiceState === 'speak') {
        amp = 0.42 + 0.28 * Math.sin(vmNow() / 110);          // lively "talking" pulse
      } else if (voiceState === 'think') {
        amp = 0.16 + 0.09 * Math.sin(vmNow() / 170);          // gentle idle shimmer
      }
      if (wrap) wrap.style.setProperty('--amp', amp.toFixed(3));
      VM.raf = requestAnimationFrame(tick);
    };
    tick();
  }

  /* ── Real-time speech-to-speech (OpenAI Realtime API over WebRTC) ────────────
     True ChatGPT-style voice: the model hears you and replies in audio directly,
     with server-side voice-activity detection (instant barge-in). Content grounding
     is preserved via a `lookup_site_info` function the model calls before answering —
     we run the lookup against the indexed site content and feed the result back. */
  const RT = {
    active:false, pc:null, dc:null, stream:null, ctx:null,
    micAnalyser:null, remoteAnalyser:null, audioEl:null, raf:null,
    muted:false, capTimer:null, connected:false, connectWatch:null, respTimer:null,
  };

  // If the model produces no audio reply within this window, fall back to chained
  function rtArmResponseTimer() {
    rtClearResponseTimer();
    RT.respTimer = setTimeout(() => {
      if (RT.active && voiceState !== 'speak') realtimeFallback();
    }, 11000);
  }
  function rtClearResponseTimer() { if (RT.respTimer) { clearTimeout(RT.respTimer); RT.respTimer = null; } }

  async function openRealtimeMode() {
    if (RT.active) return;
    if (!vmMountOverlay({ close: closeRealtime, mute: rtToggleMute, tap: () => {} })) return;
    vmResetTranscript();
    RT.active = true;
    vmSetState('idle'); vmStatus(t('vm_connecting'));
    hadActivity = true; resetIdle();

    // 1) Mint a short-lived ephemeral token from our server
    let session;
    try {
      const r = await fetch(CFG.apiUrl + '/voice/realtime-token', {
        method: 'POST',
        headers: { 'Content-Type':'application/json', 'X-WP-Nonce': CFG.nonce },
        body: JSON.stringify({ lang }),
      });
      session = await r.json();
      if (!r.ok || !session.client_secret) throw new Error('no token');
    } catch {
      // Real-time unavailable (no access / blocked) → fall back to the chained pipeline
      return realtimeFallback();
    }

    // 2) Microphone
    try {
      RT.stream = await navigator.mediaDevices.getUserMedia({
        audio: { echoCancellation:true, noiseSuppression:true, autoGainControl:true },
      });
    } catch {
      vmStatus(micDeniedMsg());
      setTimeout(closeRealtime, 2600);
      return;
    }

    setupMicAnalyser(RT.stream);

    // 3) WebRTC peer connection — the model's voice plays through the unlocked element
    const pc = new RTCPeerConnection();
    RT.pc = pc;

    pc.ontrack = e => {
      ensurePlayEl();
      try {
        try { playEl.pause(); playEl.removeAttribute('src'); } catch {}
        playEl.srcObject = e.streams[0];
        playEl.playsInline = true;
        playEl.muted = false;
        const p = playEl.play();
        if (p && p.catch) p.catch(() => {});
      } catch {}
    };
    RT.stream.getTracks().forEach(tk => pc.addTrack(tk, RT.stream));

    const dc = pc.createDataChannel('oai-events');
    RT.dc = dc;
    dc.onmessage = onRealtimeEvent;
    dc.onopen = () => { RT.connected = true; vmSetState('listen'); vmStatus(t('vm_listen')); };

    // If the connection fails (common on flaky mobile networks), fall back to chained
    pc.onconnectionstatechange = () => {
      if (RT.active && !RT.connected &&
          (pc.connectionState === 'failed' || pc.connectionState === 'disconnected')) {
        realtimeFallback();
      }
    };

    // 4) SDP offer/answer with OpenAI, authorised by the ephemeral token
    let answerSdp;
    try {
      const offer = await pc.createOffer();
      await pc.setLocalDescription(offer);
      // GA Realtime API: POST the SDP offer to /v1/realtime/calls with the ephemeral key
      const sdpRes = await fetch('https://api.openai.com/v1/realtime/calls', {
        method: 'POST',
        headers: {
          'Authorization': 'Bearer ' + session.client_secret,
          'Content-Type': 'application/sdp',
        },
        body: offer.sdp,
      });
      answerSdp = await sdpRes.text();
      if (!sdpRes.ok) throw new Error('sdp');
      await pc.setRemoteDescription({ type:'answer', sdp: answerSdp });
    } catch {
      return realtimeFallback();
    }

    rtAnimate();
    // Watchdog: if nothing connected within 9s, fall back to the chained pipeline
    RT.connectWatch = setTimeout(() => {
      if (RT.active && !RT.connected) realtimeFallback();
    }, 9000);
    // Safety cap: end a call after 5 minutes to bound cost
    RT.capTimer = setTimeout(() => { if (RT.active) closeRealtime(); }, 5 * 60 * 1000);
  }

  async function onRealtimeEvent(e) {
    let msg; try { msg = JSON.parse(e.data); } catch { return; }
    switch (msg.type) {
      case 'input_audio_buffer.speech_started':
        vmSetState('listen'); vmStatus(t('vm_listen')); break;
      case 'input_audio_buffer.speech_stopped':
        vmSetState('think'); vmStatus(t('vm_think')); rtArmResponseTimer(); break;
      case 'response.created':
        vmSetState('think'); break;
      case 'response.audio.delta':
      case 'response.output_audio.delta':
      case 'response.audio_transcript.delta':
      case 'response.output_audio_transcript.delta':
        rtClearResponseTimer();
        if (voiceState !== 'speak') { vmSetState('speak'); vmStatus(t('vm_speak')); }
        break;
      case 'response.audio.done':
      case 'response.output_audio.done':
      case 'response.done':
        if (voiceState === 'speak') { vmSetState('listen'); vmStatus(t('vm_listen')); }
        break;
      case 'conversation.item.input_audio_transcription.completed':
        if (msg.transcript) vmAddTranscript('user', msg.transcript);   // what the user said
        break;
      case 'response.audio_transcript.done':
      case 'response.output_audio_transcript.done':
        if (msg.transcript) vmAddTranscript('bot', msg.transcript);    // what the bot said
        break;
      case 'response.function_call_arguments.done':
        rtHandleFunctionCall(msg); break;
    }
  }

  /* The model asked to look something up → run grounded retrieval, feed it back */
  async function rtHandleFunctionCall(msg) {
    vmSetState('think'); vmStatus(t('vm_think'));
    rtClearResponseTimer();   // model is doing a lookup — legitimate work, don't time out yet
    let args = {}; try { args = JSON.parse(msg.arguments || '{}'); } catch {}
    let output = 'לא נמצא מידע רלוונטי.';
    try {
      const r = await fetch(CFG.apiUrl + '/voice/lookup', {
        method: 'POST',
        headers: { 'Content-Type':'application/json', 'X-WP-Nonce': CFG.nonce },
        body: JSON.stringify({ query: args.query || '', lang }),
      });
      const d = await r.json();
      if (d.text) output = d.text;
    } catch {}
    if (!RT.active || !RT.dc || RT.dc.readyState !== 'open') return;
    RT.dc.send(JSON.stringify({
      type: 'conversation.item.create',
      item: { type:'function_call_output', call_id: msg.call_id, output },
    }));
    RT.dc.send(JSON.stringify({ type:'response.create' }));
    rtArmResponseTimer();   // expect audio shortly after the lookup result
  }

  function rtToggleMute() {
    RT.muted = !RT.muted;
    if (RT.stream) RT.stream.getAudioTracks().forEach(tk => tk.enabled = !RT.muted);
    const btn = $id('m-vm-mute');
    if (btn) { btn.classList.toggle('muted', RT.muted); btn.innerHTML = RT.muted ? ICO_MIC_OFF : ICO_MIC; }
    if (RT.muted) { vmSetState('idle'); vmStatus(t('vm_muted')); }
    else if (voiceState !== 'speak') { vmSetState('listen'); vmStatus(t('vm_listen')); }
  }

  function rtAnimate() {
    const wrap = $id('m-orb-wrap');
    const buf  = new Uint8Array(512);
    const tick = () => {
      if (!RT.active) return;
      let amp = 0;
      if (voiceState === 'listen' && micAnalyser) {
        micAnalyser.getByteTimeDomainData(buf);
        let sum = 0;
        for (let i = 0; i < buf.length; i++) { const v = (buf[i] - 128) / 128; sum += v * v; }
        amp = Math.min(1, Math.sqrt(sum / buf.length) * 3.4);
      } else if (voiceState === 'speak') {
        amp = 0.42 + 0.28 * Math.sin(vmNow() / 110);   // "talking" pulse
      } else if (voiceState === 'think') {
        amp = 0.16 + 0.09 * Math.sin(vmNow() / 170);
      }
      if (wrap) wrap.style.setProperty('--amp', amp.toFixed(3));
      RT.raf = requestAnimationFrame(tick);
    };
    tick();
  }

  function closeRealtime() {
    RT.active = false;
    vmFlushTranscript();
    if (RT.raf) cancelAnimationFrame(RT.raf);
    if (RT.capTimer) { clearTimeout(RT.capTimer); RT.capTimer = null; }
    if (RT.connectWatch) { clearTimeout(RT.connectWatch); RT.connectWatch = null; }
    rtClearResponseTimer();
    RT.connected = false;
    try { RT.dc && RT.dc.close(); } catch {}
    try { RT.pc && RT.pc.close(); } catch {}
    if (RT.stream) { RT.stream.getTracks().forEach(t => t.stop()); }
    stopPlayEl();
    RT.pc = RT.dc = RT.stream = RT.ctx = RT.raf = null;
    micAnalyser = null;
    RT.muted = false;
    voiceState = 'idle';
    $id('m-vm')?.remove();
  }

  /* ── Rebuild on language change ── */
  function rebuild() {
    const wasOpen = isOpen;
    stopListen();
    stopAudio();
    endVoice();
    build();
    if (wasOpen) show(false);   // keep it open, but don't re-pop the mobile keyboard
  }

  /* ── Helpers ── */
  function scroll(el) { requestAnimationFrame(() => el.scrollTop = el.scrollHeight); }
  function nearBottom(el) { return el.scrollHeight - el.scrollTop - el.clientHeight < 80; }
  function autoH(el)  { el.style.height = 'auto'; el.style.height = Math.min(el.scrollHeight, 96) + 'px'; }
  function clock()    { return new Date().toLocaleTimeString(localeFor(lang), {hour:'2-digit', minute:'2-digit'}); }
  function esc(s)     { return (s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
  function fmt(s)     { return esc(s).replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>').replace(/\n/g,'<br>'); }

  /* ── Init ── */
  function init() {
    host = document.getElementById('wisply-root') || document.getElementById('wisply-chatbot-root');
    if (!host) return;
    host.id = 'wisply-root';

    // Attach an isolated Shadow DOM — theme CSS cannot reach inside
    if (!host.shadowRoot) {
      shadow = host.attachShadow({ mode: 'open' });
    } else {
      shadow = host.shadowRoot;
    }

    // Bind the global Escape-to-close ONCE (not per rebuild, which leaked listeners)
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && isOpen) close(); });

    // Page context for the proactive teaser (server injects the page title/department)
    pageTitle  = (host.getAttribute('data-page-title') || '').trim();
    department = (host.getAttribute('data-department') || '').trim();
    captureContext();

    build();

    // Pre-fetch page-specific questions so they're ready when the chat opens
    fetchPageQuestions();

    // Proactive bubble — pops when the visitor scrolls to the middle of the page
    if (PROACTIVE_ON) setupTeaserTrigger();

    // Desktop auto-open — opens the whole window automatically as a call-to-action
    if (AUTOOPEN_ON) setupAutoOpen();
  }

  document.readyState === 'loading'
    ? document.addEventListener('DOMContentLoaded', init)
    : init();
})();
