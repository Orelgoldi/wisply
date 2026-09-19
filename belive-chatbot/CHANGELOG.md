# Changelog — Wisply (AI Chat Assistant)

All notable changes to this plugin are documented here.
Format based on [Keep a Changelog](https://keepachangelog.com/); versioning is [SemVer](https://semver.org/).

## [2.18.0] — 2026-08-06
### Added
- **גשר חי דו-כיווני לנציג (Live handoff).** במקום רק כפתור, כשלקוח מבקש נציג והוואטסאפ
  Cloud API מוגדר: השרת שולח **התראה אוטומטית לוואטסאפ של העובד/ת** (עם סיכום השיחה),
  והשיחה נכנסת ל"מצב אנושי", הלקוח **ממשיך בצ׳אט באתר**, הודעותיו זורמות לוואטסאפ של
  הנציג, ותשובות הנציג מוזרמות חזרה לצ׳אט של הלקוח (הווידג׳ט עושה polling). הנציג כותב
  "סיום" כדי להחזיר לבוט. מצב בלי-סכימה (transients 6ש), endpoint /agent-poll עם הרשאה
  קלה (בלי מונה הקצב), ומחוון "מחובר/ת לנציג". אם ה-WhatsApp API לא מוגדר, חוזרים
  אוטומטית לכפתור click-to-WhatsApp מ-2.17.0.

## [2.17.0] — 2026-08-06
### Added
- **העברה לנציג אנושי בוואטסאפ (Handoff).** כשלקוח מבקש בצ׳אט לדבר עם נציג/בן אדם, הבוט
  מציג כפתור ירוק "המשך עם נציג בוואטסאפ" שפותח את הוואטסאפ של העובד/ת, עם **סיכום השיחה
  מוכן בהודעה** (השאלות האחרונות של הלקוח) כדי שהנציג יקבל הקשר מיד. לא דורש WhatsApp API,
  עובד מהצ׳אט באתר, מובייל ו-Web. זיהוי כפול: סמן [HANDOFF] של ה-AI + זיהוי מילות מפתח
  בשרת. הגדרה בטאב "💬 וואטסאפ": הפעלה, מספר הנציג, וטקסט כפתור.

## [2.16.0] — 2026-07-30
### Changed
- **ה-AI מנוהל פלטפורמה — אין יותר מפתחות API בתוסף.** כל פעולות ה-AI (צ'אט,
  תמלול, TTS, Realtime) עוברות דרך פרוקסי בשרת Wisply (`/api/plugin/ai`) עם מפתח
  הרישיון בלבד. בצד השרת כל לקוח מקבל פרויקט OpenAI ייעודי (Admin API) עם מפתח
  שמונפק אוטומטית בשימוש הראשון ונשמר מוצפן — המפתח לעולם לא מגיע לאתר הלקוח.
- הוסרו שדות ספק/מפתח (ai_provider, ai_api_key, openai_api_key, ai_model) ממסך
  ההגדרות ומרשימת השמירה; לשונית ה-AI מציגה כעת רק בחירת מודל (מרבי/חסכוני).
- בדיקת המערכת: "מפתח OpenAI" הוחלף ב"חיבור Wisply AI" (נוכחות מפתח רישיון).
- `raw_completion` (שאלות לעמוד, סיכומי לידים) רץ תמיד על המודל החסכוני דרך הפרוקסי.

### Security
- נעילת ספק אמיתית: אין אפשרות להזין מפתח API חלופי, ואין מפתח שאפשר לחלץ
  ממסד הנתונים של וורדפרס. ניתוק לקוח = השבתת רישיון בצד Wisply.

## [2.16.0] — 2026-07-30
### Added
- **ערוץ וואטסאפ.** הבוט עונה עכשiv גם ב-WhatsApp, עם אותו מוח AI, אותו תוכן ואותם
  מוצרים, דרך Meta WhatsApp Cloud API (חינמי). white-label לגמרי: כל לקוח מחבר את מספר
  ה-WhatsApp Business שלו. כולל: אימות webhook (verify token), אימות חתימת HMAC (App
  Secret), dedup לפי message-id, שיחה נפרדת לכל מספר טלפון (עם היסטוריה), וניקוי סמני
  הווידג'ט מהטקסט. טאב הגדרות ייעודי "💬 וואטסאפ" עם כתובת ה-Webhook להעתקה. הטוקנים
  נשמרים כשדות סוד וממוסכים, לא נחשפים לדפדפן.

## [2.15.0] — 2026-07-30
### Changed
- **אווטארים שיוצרו ב-AI ייעודית ל-Wisply.** במקום ה-DiceBear הפרוצדורלי, 12 דמויות
  מאוירות מקוריות (סגנון פלאט חם ואחיד, מגוון + רובוטים), שנוצרו עם Higgsfield / Nano
  Banana Pro, נחתכו לעיגולים נקיים עם שקיפות בפינות, ומצורפות מקומית (PNG). שייכות
  ל-Goldstein Studio. עדיין עם אפשרות העלאת תמונה מותאמת.

## [2.14.6] — 2026-07-29
### Changed
- **אווטארים יפים ועקביים במקום מוזרים.** הסגנונות הקרטוניים והאקראיים (Avataaars,
  Adventurer, Fun Emoji, Big Smile) שיצאו לפעמים מוזרים הוחלפו בסגנונות הכי נקיים ויפים
  של DiceBear: **Notionists** (6), **Lorelei** (3), **Micah** (1), לצד הרובוטים (Bottts)
  והפשוטים (Thumbs/Shapes). כולם CC0/חופשי מסחרית. ברירת המחדל היא כעת דמות נקייה.

## [2.14.5] — 2026-07-29
### Added
- **סט אווטארים מורחב ל-16, בשלוש משפחות.** נוספו **ריאליסטים** (דמויות אנוש בסגנון
  Avataaars / Adventurer) ו**פשוטים** (Thumbs / Shapes / Big Smile), לצד הרובוטים
  ופרצופי האמוג'י הקיימים. קובץ קרדיטים לרישוי צורף (`public/avatars/CREDITS.txt`).

## [2.14.4] — 2026-07-29
### Changed
- **סט אווטארים חדש שממלא את העיגול יפה.** הדמויות הקודמות נחתכו בראש. הוחלפו ב-4
  רובוטים צבעוניים (Bottts) + 4 פרצופי אמוג'י חמודים (Fun Emoji), שממלאים את העיגול
  בלי חיתוך ונראים מעולה בקטן. נוסף cache-busting (`?ver`) כי שמות הקבצים זהים.
### Fixed
- **התצוגה המקדימה החיה עלתה על כפתור השמירה.** היא היתה כרטיס צף (fixed). כעת היא
  כרטיס רגיל בתוך טאב העיצוב, בלי לחפוף לשום דבר.

## [2.14.3] — 2026-07-29
### Fixed
- **כפתור "העלאת תמונה" לא עבד.** הבדיקה של `wp.media` נעשתה בזמן טעינת הסקריפט, לפני
  שמנהל המדיה נטען (בפוטר), אז תמיד נפל ל-fallback. כעת הבדיקה בזמן הלחיצה, ו-`wp_enqueue_media`
  מוזמן גם בהוק המוקדם.
### Changed
- **אווטארים = דמויות אמיתיות, לא אייקונים.** הוחלפו 8 האייקונים ב-8 **דמויות מאוירות
  מקצועיות** (סגנונות Notionists / Lorelei / Open Peeps / Thumbs, רישיון CC0 חופשי
  מסחרית), מצורפות מקומית לתוסף (`public/avatars/`), צבעוניות עם רקע גרדיאנט. עדיין עם
  אפשרות העלאת תמונה מותאמת.

## [2.14.2] — 2026-07-29
### Fixed
- **אפשר לבחור כל אחת מ-4 השפות כשפה יחידה.** קודם בתוכנית עם שפה אחת היתה נעילה על
  עברית (שאר השפות מושבתות + ביטול סימון היה חוזר). כעת התנהגות רדיו: סימון שפה חדשה
  מבטל את הקודמת, כך שאפשר לבחור עברית/אנגלית/רוסית/ערבית, לפי הרצון.
### Changed
- **אווטארים משודרגים.** 8 הפריסטים עוצבו מחדש כצורות מלאות וחמימות עם עיניים/חיוך
  (בוט, נצנוץ, בועת צ׳אט מחייכת, אוזניות, פרצוף, לב, שקית קניות, ברק), שמתאימות לכל
  צבע מותג, במקום אייקוני-קו דקים.

## [2.14.1] — 2026-07-29
### Fixed
- **הבוט לא ידע לענות על השאלות שהוא מציג בעמוד.** השאלות המוצעות נוצרות מתוכן העמוד,
  אבל בזמן המענה `page_url` לא הועבר ל-`get_reply`, אז התשובה נשענה על חיפוש כללי
  ולעיתים החמיצה את העמוד שממנו נוצרה השאלה. כעת כתובת העמוד מועברת, תוכן העמוד המדויק
  נשלף (`url_to_postid` → `get_content_by_post`) ומוזרק כ**מקור הראשון והסמכותי**, עם
  הנחיה ל-AI לענות ממנו קודם. כך שאלה שהוצגה בעמוד תמיד נענית מאותו עמוד.

## [2.14.0] — 2026-07-29
### Added
- **דשבורד Insights אמיתי.** נוספו שני גרפים מבוססי-נתונים: **מגמת שיחות לאורך זמן**
  (גרף עמודות עם בורר 7/30/90 ימים) ו-**heatmap "מתי הגולשים הכי פעילים"** (יום×שעה),
  מצוירים ב-CSS/SVG בלי ספריות חיצוניות. נוספו למסד שאילתות `get_conversations_by_day`
  ו-`get_activity_heatmap`.
### Changed
- **קטגוריית "דרושים" בלידים כבר לא נכפית.** היא מוצגת רק כשבאמת קיימים לידים מסוג
  דרושים; עסק בלי גיוס רואה פשוט "הכל", בלי טאב וללא תגית מיותרת.

## [2.13.3] — 2026-07-29
### Changed
- **מערכת העיצוב הורחבה לכל עמודי הניהול.** ה-CSS המשותף (`admin.css`) הפך למערכת עיצוב
  מלאה (טוקנים, כרטיסים, אריחי סטטיסטיקה, טבלאות, קלטים, כפתורים, מודאלים), כך שגם
  **הדשבורד/אנליטיקות**, השיחות, הלידים והאינדוקס מקבלים את אותו מראה פרימיום, לא רק
  ההגדרות. אריחי האנליטיקה עוצבו מחדש עם פס-אקסנט צבעוני ומספרים גדולים.

## [2.13.2] — 2026-07-29
### Changed
- **פריסת סיידבר מלאה בסגנון StoreAgent.** הטאבים העליונים הוחלפו ב**נאב צדדי דביק**:
  8 קטגוריות עם אייקון, כותרת ותת-כותרת, מצב פעיל עם רקע טורקיז עדין ופס-אקסנט. פאנל
  התוכן משמאל, כרטיסים נקיים, המון אוויר. מראה פרימיום שתואם לרפרנס.

## [2.13.1] — 2026-07-29
### Changed
- **כיוונון עיצוב לפי מערכת עיצוב (spacing/type/hierarchy).** תוקנה הפריסה הצפופה
  שנצמדה לימין: שורות הטופס הפכו לגריד דו-טורי (תווית/שדה) והשדות ממלאים את הרוחב, בלי
  אזור מת. טאבים בסגנון פילים מודרני, כותרות סקשן עם פס-אקסנט, קלטים/כפתורים מלוטשים,
  והתצוגה המקדימה החיה הפכה לכרטיס צף בפינה בזמן עבודה על טאב העיצוב.

## [2.13.0] — 2026-07-29
### Added
- **ריענון ויזואלי מלא למסך ההגדרות.** עיצוב מודרני בהשראת StoreAgent/collect.chat:
  פאנלים ככרטיסים נקיים, טיפוגרפיה ומרווחים חדשים, שדות וכפתורים מעוצבים, וכפתור שמירה
  דביק.
- **באנר סטטוס עם ספירת אינדקס חיה.** באנר ירוק "העוזר מוכן ופעיל, אונדקסו N פריטים"
  כשהכל מוגדר, או באנר כתום עם התקדמות כשחסר משהו.
- **צ׳קליסט התחלה (Onboarding).** שלושה צעדים ללחיצה (רישיון, מפתח AI, אינדוקס תוכן),
  כל אחד מקפיץ לטאב המתאים, עם סימון וי כשהושלם.
- **תצוגה מקדימה חיה של הווידג׳ט** בטאב עיצוב, שמתעדכנת בזמן אמת כשמשנים צבע, אוואטר,
  כותרת או הודעת פתיחה.
- **פרסונליזציה של אוואטר הבוט.** בטאב עיצוב אפשר לבחור את אייקון הבוט מתוך 8 פריסטים
  (רובוט, נצנוץ, בועת צ׳אט, אוזניות, דמות, לב, חנות, ברק), או להעלות/להדביק תמונה
  מותאמת (לוגו/פרצוף) דרך ספריית המדיה של וורדפרס. האוואטר מוצג בכותרת הצ׳אט ובבועית
  ההזמנה. בורר בסגנון עיגולים לבחירה עם תצוגה חיה.

## [2.12.6] — 2026-07-29
### Changed
- **הקרוסלה כבר לא קופצת על בירורי שירות.** "אפשר להזמין הרצאות לארגון?" הוא בירור B2B,
  לא בקשה לראות קטלוג. ההצגה האוטומטית צומצמה: השרת מציג קרוסלה רק על **עיון מפורש**
  ("הצג", "מה יש", "אילו", "קטלוג", "רשימה") או **סינון מחיר**, ולא על אזכור קטגוריה או
  פועל "להזמין" בלבד. המלצה על פריט ספציפי ובירורי שירות מנוהלים ע"י ה-AI (שכעת מקבל את
  המוצרים בזכות תיקון ה"א הידיעה), עם הוראה לענות בשיחה על בירור שירות ולא לשפוך קטלוג.

## [2.12.5] — 2026-07-29
### Fixed
- **הגורם השורשי ל"אין לי מידע על הרצאות": ה"א הידיעה שברה את החיפוש.** מנוע ההתאמה
  משווה תחיליות, כך ש"מה **ההרצאה** הקרובה" (עם ה' הידיעה) לא התאים לקטגוריה "הרצאות",
  והחיפוש החזיר ריק, בעוד "איזה **הרצאה**" (בלי ה') כן עבד. לכן הבוט קיבל אפס מוצרים
  ונפל ל"אין מידע". התיקון: התאמה עמידה לתחיליות עבריות (ה/ו/ב/כ/ל/מ/ש), שמנסה גם את
  הצורה בלי התחילית בלי לפגוע במילות שורש. אומת בסימולציה, בלי רגרסיה בקטגוריות אחרות.

## [2.12.4] — 2026-07-29
### Fixed
- **הבוט התעלם ממידע שיש לו והשיב "אין לי מידע".** כששאלו על הרצאה קרובה, הבוט נפל
  ל"אין מידע + השאירו פרטים" למרות שהרצאות הן מוצרים בחנות ושמן כולל תאריך ומיקום
  ("04.08 חולון"). נוספה הוראה חד-משמעית: כשקיים בבלוק המוצרים פריט רלוונטי לשאלה,
  חובה לענות ממנו ולהציג כרטיסים, ואסור לומר "אין לי מידע" או להפנות להשארת פרטים.

### Changed
- **הבוט הפך למוכר יזום, לא קטלוג.** כשיש פריט אחד שהכי עונה לשאלה (ההרצאה הקרובה,
  ההתאמה הטובה ביותר) הבוט מוביל איתו **ספציפית**: נוקב בשם, מוסר תאריך/מיקום/מחיר,
  ומציע מיד את הצעד הבא ("רוצה שאתפוס לך כרטיס?") עם כרטיס הפריט הבודד וכפתור הרכישה.
  מעדיף המלצה על פריט ספציפי על פני רשימה כללית, אלא אם ביקשו לראות את כל האפשרויות.

## [2.12.3] — 2026-07-29
### Fixed
- **כרטיסים חזרו להופיע לשאלות קניה, בלי להופיע לשאלות מידע.** 2.12.2 היה קיצוני מדי
  (הסתמך על סמן שה-AI לא תמיד שולח, אז נעלמו הקרוסלות). כעת **השרת מחליט לפי כוונה**:
  מציג קרוסלה כשיש כוונת קניה/עיון (סינון מחיר, קטגוריה, "הצג", "לקנות", "להזמין",
  "כרטיסים", "מחיר", "מבצע"), ולא מציג בשאלת הגדרה ("מה זה X", "ספר לי על Y").
- **הזמנת פריט שנענה מתוכן האתר.** כשגולש רוצה להזמין/להירשם/כרטיסים לפריט שנמכר בחנות
  (הרצאה, סדנה, קורס) והשאלה כבר לא מכילה את שם הפריט, הבוט היה עונה "אין לי מידע" ונופל
  להשארת פרטים. כעת הוא מציג את כרטיס הפריט עם [SUGGEST:] לפי נושא השיחה, והכרטיס נושא
  את כפתור הרכישה. השארת פרטים נשמרת רק כשאין פריט תואם בחנות.

## [2.12.2] — 2026-07-29
### Changed
- **הבוט לא מקפיץ יותר כרטיסי מוצר על כל שאלה.** קודם השרת חיפש מוצרים לכל הודעה
  והלקוח הציג כרטיסים אוטומטית בכל התאמה, כך שגם שאלת תוכן ("מה זה הסדנה") גררה
  קרוסלה. עכשiv: הבוט **עונה קודם** על השאלה, וכרטיסים מוצגים רק כשהגולש באמת מבקש
  מוצרים (בקשה ישירה / שאלת מחיר / קטגוריה / התאמה). בשאלת מידע הוא עונה ואז **מציע**
  ("רוצה שאראה לך את הפריטים הקשורים?") עם כפתורי כן/לא, ורק באישור מציג אותם. הוסרה
  הצגת הכרטיסים האוטומטית מ-product_ids של השרת, הכרטיסים תלויים כעת בסמן [PRODUCTS:]
  מפורש בלבד.

## [2.12.1] — 2026-07-20
### Fixed
- **גלילה נעולה לגמרי (שני הצירים) על אתרים עם אפקטי גלילה.** אתר המארח יכול להריץ
  ספריית smooth-scroll / scroll-hijack (Lenis, Locomotive, fullPage, אפקטי בונה עמודים)
  שמאזינה על window/document ומבטלת wheel/touch גלובלית, מה שמקפיא גם את הגלילה בתוך
  הווידג'ט. נוסף בידוד גלילה: אירועי wheel/touch באזור ההודעות נעצרים מלהתפשט החוצה,
  כך שהמנגנון של הדף לא רואה אותם, והדפדפן גולל את הרשימה והקרוסלה כרגיל.

## [2.12.0] — 2026-07-20
### Added
- **אזור "חוקים והתאמה חכמה" בהגדרות (פר-לקוח).** שדה טקסט חופשי בטאב AI שבו בעל
  העסק כותב כללים, ידע, טון, ולוגיקת התאמה ("מתנה ליום נישואין -> שרשרת אלגנטית",
  "לשמח -> פריט צבעוני"). נשמר בהגדרות ההתקנה בלבד (לא בקוד התוסף), ברירת מחדל ריק,
  כך שכל לקוח מגדיר משלו וזה לא משפיע על אף אחד אחר. מוזרק גבוה ב-system prompt.
- **התאמת מוצר חכמה לפי מה שהלקוח מתאר, לא לפי מילות חיפוש.** כשהלקוח מתאר רגש,
  אירוע, נמען או צורך ("מחפשת מתנה לאמא", "משהו שישמח אותי", "אני מרגישה חגיגית"),
  הבוט מבין את הכוונה לפי חוקי בעל העסק ואוצר המילים של הקטגוריות בחנות, מסביר בקצרה
  למה זה מתאים, ומציג את הפריטים המתאימים ככרטיסים (דרך [SUGGEST:]). אם חסר פרט
  (תקציב/למי) הוא שואל שאלה קצרה אחת קודם. בלי חוקים מוגדרים, נופל להיגיון קמעונאי בריא.

## [2.11.2] — 2026-07-20
### Fixed
- **אי אפשר היה לגלול את רשימת ההודעות כלל (גם בלי מוצרים).** באג flexbox קלאסי:
  `.m-msgs` הוא `flex:1` בתוך `.m-win` (flex column, גובה קבוע, overflow:hidden), אבל
  חסר לו `min-height:0`. בלי זה הוא שמר על גובה-תוכן, גלש מעבר לחלון ונחתך ע"י האב
  במקום לגלול את עצמו. נוסף `min-height:0` (+ `overscroll-behavior:contain`), וכעת
  רשימת ההודעות גוללת כרגיל.

## [2.11.1] — 2026-07-20
### Fixed
- **אי אפשר היה לגלול את השיחה כלפי מעלה במובייל.** שתי סיבות: (1) קרוסלת המוצרים
  האופקית בלעה מחוות גלילה אנכית של האצבע — נוסף `touch-action:pan-x` כך שאופקי נשאר
  בקרוסלה ואנכי עובר לרשימת ההודעות; (2) טעינת תמונה הקפיצה לתחתית תוך כדי גלילה
  למעלה — כעת קופצים לתחתית רק אם הגולש כבר קרוב אליה.

## [2.11.0] — 2026-07-19
### Added
- **בדיקת סטטוס הזמנה בצ׳אט (מאובטח).** הלקוח שואל "איפה ההזמנה שלי?", הבוט מזהה
  ומציג טופס קצר: מספר הזמנה + המייל שאיתו הוזמן. המערכת בודקת מול WooCommerce
  ומחזירה סטטוס (בהכנה / נשלחה / הושלמה), תאריך, פריטים, סכום, ומספר מעקב אם קיים.
  **אבטחה**: שני הפרטים חייבים להצטלב (כמו טופס מעקב ההזמנות המובנה של WooCommerce),
  כך שמייל בלבד לא חושף הזמנות של אף אחד. REST route `/order-status` מוגבל-קצב,
  והתשובה על "לא נמצא" גנרית כדי למנוע ניחוש. ניתן לכיבוי בטאב החנות.

## [2.10.1] — 2026-07-19
### Fixed
- **כפתור הבאנדל לא מופיע יותר על הקרוסלה המשלימה עצמה** (רק על תוצאות רגילות).
- **הבוט לא מציג מוצרים משלימים לפני שהלקוח ענה על שאלת ההכוונה.** ההנחיה חודדה
  לשני שלבים נפרדים (שאלה, ואז המלצה), ונוספה הגנת-לקוח: אם באותה הודעה יש גם שאלה
  ([OPTIONS]) וגם [SUGGEST], התוצאות מוחזקות עד שהלקוח עונה.

## [2.10.0] — 2026-07-19
### Added
- **התאמת מוצר משלים (Cross-sell) מונחית-AI.** אחרי הצגת מוצרים מופיע כפתור
  "✨ שאתאים לך מוצר משלים?". בלחיצה הבוט מנהל שיחה קצרה וחכמה: שואל שאלה ממוקדת
  (למי, לאיזה אירוע, סגנון) בכפתורי בחירה, ואז ממליץ על פריט משלים **מקטגוריה אחרת**
  (לטבעת -> עגילים/שרשרת) עם נימוק אישי, והפריט מוצג ככרטיסים אמיתיים מהחנות. מנוהל
  ע"י סמן [SUGGEST:] חדש + REST route `/product-query`. ניתן לכיבוי בהגדרות.
- **טאב הגדרות "🛒 חנות" נפרד** בממשק הניהול. כל הגדרות החנות (הפעלה, מספר מוצרים,
  מלאי, חיפוש לפי תמונה, התאמת מוצר משלים) רוכזו בטאב ייעודי במקום להיות קבורות תחת
  "לידים".

## [2.9.0] — 2026-07-19
### Added
- **מנוע שאילתות חכם למוצרים.** הבוט מבין ומבצע שאילתות שחיפוש טקסט לא יכול:
  - **תקציב / תקרת מחיר**: "מה יש בטבעות עד 300 שח", "שרשראות מתחת ל-500".
  - **טווח מחירים**: "טבעות בין 200 ל-500".
  - **מיון**: "הכי זולים", "הכי יקרים", "הכי נמכרים" (לפי מכירות בפועל), "הכי חדשים".
  - **הכל משולב עם קטגוריה**: "העגילים הכי זולים", "מה נמכר הכי טוב בשרשראות".
  - **נפילה חכמה**: אם אין מוצר בדיוק בתקציב, הבוט מציג את הקרובים ביותר ואומר זאת
    בכנות ("אין מתחת ל-300, הכי זול הוא 340"). התוצאות מוגשות ככרטיסים הרגילים.
  שאילתות סינון מוגשות מדויקות ומסודרות, בלי הזרקת "מוצרים דומים" ששוברת את הדירוג.

### Fixed
- **כרטיסי המוצר נחתכו לפס תמונה בלבד (בלי שם/מחיר/כפתור).** באג פריסה של flexbox:
  שורת הכרטיסים (`.m-products`) יושבת בתוך רשימת הודעות שהיא flex column, ובגלל
  ה-`overflow-x:auto` שלה היא הפכה ל-scroll container שה-min-height האוטומטי שלו
  מתאפס לאפס. כילד שניתן להתכווץ, השורה נדחסה כמעט לאפס, הכרטיסים נמתחו לגובה הזעום
  והגוף שלהם (שם, מחיר, כפתור) נחתך ב-`overflow:hidden`. נותרה רק פרוסת תמונה.
  התיקון: `flex-shrink:0` על השורה, כך שהיא שומרת על גובהה הטבעי. נוספה גם גלילה
  מחדש כשכל תמונה נטענת, כדי שהכרטיסים המוגמרים ייכנסו לתצוגה.
- **מחיר כפול במוצר עם וריאציות** ("₪240 – טווח מחירים:₪1,600 ₪240 עד ₪1,600").
  WooCommerce מוסיף `<span class="screen-reader-text">` נסתר לטווח המחיר; ב-Shadow DOM
  שלנו אין כלל CSS שמסתיר אותו ו-`wp_kses` השאיר את הטקסט. כעת הספאן הזה מוסר לגמרי
  לפני הסניטציה (`clean_price_html`), הן במוצר והן בוריאציות.

### Added
- **אינדיקציית גלילה לקרוסלת המוצרים** — נקודות מתחת לכרטיסים שמסמנות שיש עוד לגלול,
  והנקודה הפעילה עוקבת אחרי מיקום הגלילה.
- **כפתור "לכל הקטגוריה"** מתחת לקרוסלה, שמפנה לעמוד הקטגוריה שרוב המוצרים שייכים אליה
  (קטגוריות גנריות כמו Uncategorized/SALE/Gift card מסוננות).

### Improved
- **חיפוש לפי תמונה מדייק בסוג הפריט.** הניקוד שוקלל: התאמת סוג פריט (טבעת/שרשרת/עגיל/
  צמיד) מקבלת משקל דומיננטי, מעליה חומר/נושא (זהב/מבצע) משקל בינוני, ומילות שם רגילות
  משקל נמוך. כך תמונת טבעת מחזירה קודם טבעות, ולא צמיד זהב אקראי. גם הנחיית הזיהוי
  החזותי חודדה כך שסוג הפריט תמיד מופיע ראשון.

## [2.8.4] — 2026-07-19
### Fixed
- **הבוט מבין קטגוריות בעברית גם כששמן באתר באנגלית.** מנוע החיפוש נכתב מחדש: הוא **לומד
  פעם אחת** (ומטמן) את שמות המוצרים והקטגוריות, מדרג כל מוצר מול מילות השאילתה בהתאמת
  תחילית (רבים↔יחיד: "שרשראות"↔"שרשרת"), ומגשר עברית↔אנגלית עם מפת מילים נרדפות
  (שרשראות→Necklaces, טבעות→Rings, זהב→Gold...). מחיר ומלאי נשלפים **חי** לתוצאות
  המובילות בלבד. כך "הצג לי שרשראות" מוצא את הקטגוריה Necklaces בלי שתכתוב אנגלית.
- **כלי האבחון מראה כעת גם `card_preview`** — בדיוק מה שהשרת מוסר לכרטיסים (שם, מחיר,
  אם יש תמונה). אם זה מלא אבל אין כרטיסים בצ'אט — צריך רענון קשיח (המטמון מגיש JS ישן).

## [2.8.3] — 2026-07-19
### Fixed
- **חיפוש מוצרים עמיד לנִיקוד.** מנוע החיפוש נכשל בהתאמת מוצרים/קטגוריות מנוקדים
  ("שֶׁפַע") מול שאילתה בלי ניקוד, ולכן הבוט ענה מטקסט מאונדקס במקום כרטיסים. כעת
  הכל מנורמל (הסרת ניקוד), זיהוי קטגוריה מתבצע לפי מילים, ונוסף fallback שסורק שמות
  מוצרים ב-PHP — התאמה שוורדפרס עצמו לא יכול לעשות מול שם מנוקד.
- **שאלות על מוצרים לא מציעות עוד "השאירו פרטים".** בהקשר חנות הפעולה היא הכרטיס
  והמעבר לעמוד המוצר, לא לכידת ליד. הצעת פרטים תופיע רק אם הגולש ביקש הצעת מחיר/ייעוץ.
### Added
- **כלי אבחון חנות (אדמין).** `‎/wp-admin/admin-ajax.php?action=wisply_woo_diagnose&q=...`‎
  מחזיר כמה מוצרי WooCommerce אמיתיים יש, קטגוריות, ומה החיפוש מוצא — כדי לאבחן
  "למה אין כרטיסים" בלי לנחש.

## [2.8.2] — 2026-07-19
### Fixed
- **כרטיסי המוצר מופיעים עכשיו באמת.** עד כה רינדור הכרטיסים היה תלוי לגמרי בכך שה-AI
  יפלוט את הסמן `[PRODUCTS:]` — והוא לא עשה זאת בעקביות, ולכן הגולש קיבל רשימת טקסט.
  כעת **השרת מחזיר את מזהי המוצרים שמצא** לכל שאילתה, והוויג'ט מרנדר את הקרוסלה מהם
  (או מהסמן, אם ה-AI כן פלט אחד). כך הכרטיסים מופיעים בכל פעם שיש מוצרים רלוונטיים,
  בלי תלות בציות של המודל.

## [2.8.1] — 2026-07-19
### Changed
- **מוצרים מוצגים ככרטיסים, לא כרשימת טקסט.** כשהגולש מבקש לראות מוצרים או קטגוריה,
  הבוט מציג כעת **קרוסלת כרטיסים נגללת** (תמונה, שם, מחיר, מלאי, כפתור לצפייה) במקום למנות
  שמות בטקסט. ההנחיה ל-AI חוזקה: כשמוזכר מוצר מבלוק החנות — חובה לפלוט את הסמן `[PRODUCTS:]`
  ולא לחזור על השם/המחיר בטקסט (הכרטיסים כבר מציגים הכל). התשתית לכרטיסים ולגלילה כבר
  הייתה קיימת; זה מבטיח שהיא באמת מופעלת.
- **יותר מוצרים בקרוסלה.** ברירת המחדל של "מספר מוצרים בתשובה" עלתה מ-4 ל-8, והמקסימום
  מ-8 ל-12 — כדי שדפדוף בקטגוריה יציג קרוסלה מלאה שאפשר לגלול בה.

## [2.8.0] — 2026-07-19
### Added
- **ערבית כשפה רביעית מלאה.** הבוט עונה בערבית (זיהוי שפה, RTL, קול והקראה, טקסטי ברירת מחדל וכל שדות התוכן בפאנל — `greeting_ar`, `suggested_questions_ar`, כותרות, הודעות יזומות, הסכמה, חירום, ותוויות כפתורי CTA).
- **בורר שפות בהגדרות (הגדרות → עיצוב → 🌐 שפות).** בעל האתר בוחר אילו שפות פעילות — אחת, כמה, או את כולן — ומהי שפת ברירת המחדל. הוויג'ט מציג בורר שפה לגולש רק כשמסומנת יותר משפה אחת.
- **בחירת שפה לפי מסלול.** מספר השפות הפעילות מוגבל לפי התכנית: Spark / Lite — שפה אחת; Business / Pro / Enterprise — כל 4 השפות. הגבלה נאכפת גם בשמירה וגם בזמן הצגת הוויג'ט (clamp), עם ברירת מחדל להתקנה חדשה לפי שפת האתר.

### Changed
- שרת הרישוי מחזיר כעת `max_langs` לפי המסלול (`plan_max_langs`), והתוסף גוזר את השפות בהתאם. הערך "דביק" — אישור חד-פעמי של מסלול נשמר ולא יורד בגלל תקלת שרת זמנית (אותה דוקטרינת fail-open של הגייט).

## [2.7.0] — 2026-07-15
### Added
- **רישוי ועדכונים אוטומטיים.** שדה **מפתח רישיון** בהגדרות → מתקדם (`WSP-XXXX-XXXX-XXXX-XXXX`).
  מרגע שהוא מוזן, עדכוני גרסה מגיעים ישירות בוורדפרס ("עדכון זמין" → **עדכן**) — בלי
  להעביר קובצי zip ידנית. כולל מסך "צפייה בפרטים" עם ה-changelog.
- הרישיון מוגבל למספר אתרים לפי המסלול. אתר שכבר הופעל אף פעם לא נחסם בשמירה חוזרת;
  רק אתר **חדש** צורך מקום במכסה.
- שרת הרישוי נקבע ב-`WISPLY_API_URL` וניתן לעקיפה ב-`wp-config.php`.

### Changed
- **הבוט נחסם כשהרישיון נדחה במפורש** (מנוי שבוטל / פג תוקף / חריגה ממכסה), אחרי **7 ימי חסד**.
  הוויג'ט לא מוצג והמסלולים הציבוריים מחזירים 403. מסלולי האדמין לעולם לא נחסמים —
  בעל האתר תמיד יכול להגיע להגדרות ולתקן את המפתח.
- **התקנה ללא מפתח כלל ממשיכה לעבוד** (עם התראה באדמין). זו החלטה מכוונת: אחרת כל אתר
  קיים היה מת ברגע העדכון לגרסה הזו, לפני שהספיק לקבל מפתח.

### Security / Reliability
- **Fail-open**: תקלה בשרת הרישוי שלנו, timeout, או תשובה לא מוכרת **לא** מכבים את הבוט.
  חסימה קורית רק על סיבת דחייה מפורשת מתוך רשימה סגורה (`not_found`/`suspended`/`canceled`/
  `expired`/`quota`/`not_activated`). דחייה היא opt-in, לעולם לא מסקנה.
- חלון החסד נשען על דגל `license_ever_active` נפרד מהסטטוס החי, כך שניתוק רשת רגעי
  לא מוחק את העובדה שהרישיון עבד כאן (אחרת החסד כמעט אף פעם לא היה נדלק).
- קובץ ההתקנה מוגש מדלי **פרטי** דרך `/api/download` בלבד, אחרי בדיקת רישיון חיה,
  עם אסימון מוצפן קצר-מועד — במקום מפתח הרישיון בתוך ה-URL (שוורדפרס שומר ב-`wp_options`).

## [2.6.0] — 2026-07-15
### Added
- **Lead-form field control** — each of שם / טלפון / אימייל can be set to **חובה / רשות / מוסתר**.
  Enforced identically in the widget and on the server (a hidden field is forced empty
  server-side and never rendered client-side).
- **End-of-conversation CTA** — choose what the visitor sees when a chat ends:
  טופס השארת פרטים / כפתור התקשרות / גם וגם / כלום.
- **Message limit per conversation** — cap how many messages a visitor may send
  (0 = unlimited). A configurable number of messages before the cap the bot starts
  converging: it stops opening new topics, summarises, and pushes for the lead. At the
  cap the conversation closes with the chosen CTA (and the AI is not called at all).
  New settings under 📥 לידים: שדה שם/טלפון/אימייל, בסיום שיחה, מקסימום הודעות, התחלת התכנסות.

### Fixed
- The lead form now **shows the server's rejection reason** instead of a click that
  silently does nothing (missing required field / no contact method / consent).
- "At least one contact method" is now enforced **only when a contact field is visible**,
  and the widget mirrors the rule — previously hiding the phone field could make leads
  silently unsubmittable.
- The end-of-conversation message is **localised** (he/en/ru) and no longer promises a
  call-back when the CTA is set to כפתור התקשרות or כלום.

## [2.5.0] — 2026-07-15
### Added
- **🛒 E-commerce module (WooCommerce).** The bot now answers about the real catalogue
  using **live** data — no REST keys needed, it reads WooCommerce's PHP API in-process,
  so prices and stock are always current.
  - **Products, live prices, stock and variations.** Matching products are injected into
    the AI context; the model is told to quote *only* that data. For variable products it
    asks which option (size/colour) and answers with that variant's price/stock; if a
    variant is out of stock it says so and offers an alternative.
  - **Rich product cards in chat** — image, price (with sale strikethrough preserved),
    stock badge and a link to the product, via a new `POST /products` route and a
    `[PRODUCTS: id,id]` marker.
  - **Similar products** — related items for the top hit are pulled in so the bot can
    offer alternatives (especially when something is out of stock).
  - **Shipping** — live WooCommerce shipping zones/rates, injected only when the visitor
    actually asks about delivery (keeps prompts cheap).
  - **Visual search (optional)** — the visitor uploads a photo, a vision model turns it
    into search terms, and the bot returns matching products (`POST /product-image-search`).
  - Settings under 📥 לידים: enable, max products (1–8), show stock, visual search.
    Degrades safely: no WooCommerce installed → module simply stays off.
  Note: this module is Wisply-only — it is intentionally not mirrored to Medical360.

## [2.4.2] — 2026-07-15
### Fixed
- **False "job-seeker" classification** — the marketing-vs-job classifier scanned the bot's
  replies too (which may quote site content), causing false job matches. Now it classifies
  from the visitor's own messages only.

## [2.4.1] — 2026-07-15
### Fixed
- **Leads CSV export came out as gibberish** — moved the export to an early `admin_init`
  handler (before any HTML output) with a clean UTF-8 BOM so Excel reads Hebrew correctly.
### Changed
- **Export columns now mirror the on-screen leads table** (תאריך, שם, סוג, טלפון, אימייל,
  התעניינות, סיכום, מחלקה, שיחה מלאה, מקור, קמפיין, Opt-In). Newlines inside a cell are
  collapsed so each lead stays on one row.

## [2.4.0] — 2026-07-14
### Removed
- **Logicare CRM integration removed entirely.** Logicare is a client-specific Israeli
  CRM (Paz Medical Care) and is not relevant to a resellable white-label product. Removed
  the enable toggle, Base URL, API key, `send_to_logicare`, System-Check CRM row, the CRM
  status column/badge in the leads table + CSV + reports, and all `logicare_*` settings.
  A future generic CRM should be a provider-agnostic webhook, not Logicare.
  (The "לידים ו-CRM" settings tab is now simply "לידים".)

## [2.3.0] — 2026-07-06
### Added
- **Desktop auto-open (call-to-action)** — optional setting that automatically opens
  the full chat window on desktop after a configurable delay, with an editable opening
  message per language (HE/EN/RU). Desktop-only (not mobile), fires once per session,
  and respects the visitor (won't reopen if they've closed it or started typing).
  Settings: enable checkbox + delay + 3 message fields, under the proactive tab.

## [2.2.1] — 2026-07-06
### Added
- **Smart job-seeker flow** — when a visitor names a *specific* role/position they're
  after (any language — HE/EN/RU), the bot does both in one reply: links to the careers
  page (`[ACTION:jobs]`) **and** offers to leave details for a call-back (`[ASK_LEAD]` →
  lead form). Active when a `jobs` action button is configured. Lead is auto-tagged `job`.

## [2.2.0] — 2026-07-05
### Added
- **Lead classification (marketing vs. job-seeker)** — every inquiry is auto-tagged
  `marketing` / `job` by scanning the conversation for career keywords (HE/EN/RU).
  Leads screen gets filter tabs (הכל / 🎯 שיווקי / 💼 דרושים) with live counts, a
  per-lead type badge, and a type-aware CSV export (adds a "סוג" column).
- **Automated leads reports** — daily (every morning) + weekly (Monday) email digests
  to configurable recipients. Each report splits marketing vs. job-seeker leads into
  HTML tables and attaches a period CSV. New settings: recipient emails + daily/weekly
  toggles (WP-Cron `wisply_daily_leads_report` / `wisply_weekly_leads_report`).
- **Tabbed settings screen** — the long settings page is now organised into tabs
  (🏷️ מיתוג · 🤖 AI · 🎙️ קול · 🔔 בועית יזומה · 📥 לידים ו-CRM · 🎨 עיצוב ותוכן · ⚙️ מתקדם),
  a single form so everything still saves together; active tab persists via localStorage.

### Fixed
- Settings save/display hardening — `wp_unslash` on all inputs, textarea fields keep
  their newlines, checkboxes normalise to 0/1, and dropdowns/values read live from the
  DB store so saved settings always render (no reverting to generic defaults).

## [2.1.0] — 2026-07-01
### Added
- **Logicare CRM integration** — every captured lead is pushed to Logicare
  (`POST /logicare/api/new_lead/`) in addition to the email + dashboard. Maps
  name/phone/email + AI summary & transcript (`details`), interest (`referrer_notes`),
  source (`referrer`), `campaign`, `landing`, `department_name`.
- Settings: enable toggle, **Base URL**, and encrypted **API Key** (company UUID).
- **System Check** validates the CRM key via `/logicare/api/auth/` and shows the company name.
- Per-lead CRM sync status (✓/✗) shown in the leads table + CSV export.

## [2.0.0] — 2026-06-29
Brought to full feature-parity with the internal Medical360 build (v4.2.0), kept fully white-label/generic.

### Added
- **Real-time voice (OpenAI Realtime API, GA endpoints)** — `/v1/realtime/client_secrets` + `/v1/realtime/calls`, model `gpt-realtime`; content-grounded via a `lookup_site_info` tool. Falls back to the chained Whisper→chat→TTS pipeline if unavailable.
- **Voice text modes** — none / save-transcript-on-hangup / live transcript.
- **Proactive page teaser** — contextual bubble that pops when the visitor scrolls to mid-page; message + 4 AI-generated, page-specific suggested questions.
- **Context Engine** — captures UTM source/medium/campaign, referrer, landing page and page/department, attached to every lead.
- **Marketing consent (Opt-In)** — required checkbox with editable text/version; stores consent + timestamp + version per lead. Default text is a generic placeholder to adapt per business/law.
- **Extended lead model + AI conversation summary** — source, channel, department, campaign, landing page, conversation length, status, and a one-line AI summary; shown in admin + email + CSV.
- **Guided lead flow** — `[OPTIONS: a | b | c]` choice buttons → `[ASK_LEAD]` yes/no → form, instead of popping a form immediately.
- **Emergency escalation** — the bot stops and shows a configurable emergency message + action buttons (`[EMERGENCY]`). Ships with Israeli defaults (101, ER"N, SAHAR), all editable.
- **Analytics dashboard** — conversations, leads, conversion rate, Opt-In rate, abandoned conversations, top questions, top departments.
- **System Check** tool + per-voice **preview** button in settings.
- **"Start a new chat"** CTA on the inactivity timeout (managed conversation end).

### Fixed
- Mobile audio playback (iOS): unlock + DOM-attached `<audio>` element.
- Language switching no longer rebuilds the whole widget (relabel-in-place; no more freezes; preserves history).
- Admin pages send `no-store` headers (settings no longer appear to "revert" from cache).
- Timestamps use the WordPress timezone (`current_time`) for day grouping.
- Settings save uses explicit UPDATE/INSERT (robust to legacy table schemas).

### White-label
- Generic persona throughout (no medical wording) via `persona()` / `available_actions()`.
- Configurable `product_name`, `bot_name`, `business_name/type/description`, `suggested_questions_he/en/ru`, and a custom **CTA buttons** repeater.
- `bot_name`/`business_name` auto-fill from the WordPress site title on activation.
- `WISPLY_PRODUCT_NAME` constant drives the admin menu + "Powered by" credit.

## [1.0.2] — 2026-06-15
Initial white-label build derived from the Medical360 chatbot: renamed namespace/classes/tables (`Wisply_*`, `wisply_*`, `wisply/v1`), settings-driven persona, configurable product name, text + voice (Whisper/TTS) chat grounded only in the site's own content. Hebrew / English / Russian, RTL, WCAG-aware.
