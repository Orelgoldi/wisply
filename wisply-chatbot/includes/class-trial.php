<?php
defined( 'ABSPATH' ) || exit;

/**
 * Free-trial state machine for the Wisply product.
 *
 * A self-serve 14-day / 100-conversation trial that runs the bot on a cheaper AI
 * model and pauses gracefully (retaining all config + data) the moment either
 * limit is reached. Billing / card-at-signup is intentionally NOT handled here —
 * see TRIAL-WIZARD-PLAN.md; this class owns only the trial state, counters and gate.
 *
 * State (stored in the plugin settings table, via Wisply_Database):
 *   trial_status      '' | 'active' | 'ended' | 'converted'
 *   trial_started_at  unix timestamp of trial start
 *   trial_convo_count visitor-driven conversations consumed so far
 *
 * The gate is inert until start_trial() runs (from the setup wizard's finish step),
 * so an existing/paid install is never affected — status '' → is_trial_mode() false.
 */
class Wisply_Trial {

    private static ?self $instance = null;
    private Wisply_Database $db;

    /** Trial length in days and the hard conversation cap — whichever comes first ends it. */
    public const TRIAL_DAYS      = 14;
    public const TRIAL_CONVO_CAP = 100;

    private function __construct() {
        $this->db = Wisply_Database::get_instance();
        if ( is_admin() ) {
            add_action( 'admin_notices', [ $this, 'admin_notice' ] );
        }
    }

    public static function get_instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ─── State ────────────────────────────────────────────────────────────────

    public function status(): string {
        return (string) $this->db->get_setting( 'trial_status', '' );
    }

    /** A trial exists and has not been converted to paid — i.e. the gate is live. */
    public function is_trial_mode(): bool {
        return in_array( $this->status(), [ 'active', 'ended' ], true );
    }

    public function started_at(): int {
        return (int) $this->db->get_setting( 'trial_started_at', 0 );
    }

    public function conversation_count(): int {
        return (int) $this->db->get_setting( 'trial_convo_count', 0 );
    }

    // ─── Transitions ──────────────────────────────────────────────────────────

    /** Begin the trial (idempotent — never restarts an active or converted trial). */
    public function start_trial(): void {
        if ( in_array( $this->status(), [ 'active', 'converted' ], true ) ) {
            return;
        }
        $this->db->set_setting( 'trial_status', 'active' );
        $this->db->set_setting( 'trial_started_at', (string) time() );
        $this->db->set_setting( 'trial_convo_count', '0' );
    }

    /** Pause the bot when a cap is hit. Only moves an active trial to 'ended'. */
    public function mark_ended(): void {
        if ( $this->status() === 'active' ) {
            $this->db->set_setting( 'trial_status', 'ended' );
        }
    }

    /**
     * Flip to paid. Called by the (future) billing layer once a card has been
     * successfully charged — see TRIAL-WIZARD-PLAN.md. Clears the gate entirely.
     */
    public function mark_converted(): void {
        $this->db->set_setting( 'trial_status', 'converted' );
    }

    public function increment_conversation(): void {
        $this->db->set_setting( 'trial_convo_count', (string) ( $this->conversation_count() + 1 ) );
    }

    // ─── Windows ──────────────────────────────────────────────────────────────

    public function days_left(): int {
        if ( $this->started_at() === 0 ) {
            return self::TRIAL_DAYS;
        }
        $elapsed = (int) floor( ( time() - $this->started_at() ) / DAY_IN_SECONDS );
        return max( 0, self::TRIAL_DAYS - $elapsed );
    }

    public function conversations_left(): int {
        return max( 0, self::TRIAL_CONVO_CAP - $this->conversation_count() );
    }

    /** True only while the trial is running AND still under both caps. */
    public function is_active(): bool {
        return $this->status() === 'active'
            && $this->days_left() > 0
            && $this->conversations_left() > 0;
    }

    // ─── Cheaper-model switch ─────────────────────────────────────────────────

    /** The cheaper model to run while on trial, per provider (settings-overridable). */
    public function trial_model( string $provider ): string {
        return $provider === 'openai'
            ? (string) $this->db->get_setting( 'trial_model_openai', 'gpt-4o-mini' )
            : (string) $this->db->get_setting( 'trial_model_claude', 'claude-3-5-haiku-latest' );
    }

    /**
     * Resolve the model to actually use for a turn: the cheap trial model while the
     * trial is active, otherwise the owner's configured (premium) model unchanged.
     */
    public function effective_model( string $provider, string $configured ): string {
        return $this->is_active() ? $this->trial_model( $provider ) : $configured;
    }

    // ─── Graceful pause message ───────────────────────────────────────────────

    public function upgrade_message( string $lang ): string {
        return match ( $lang ) {
            'en' => 'Thanks for trying the assistant! 🙏 The free trial has ended. To keep the bot live, please upgrade — all your settings and data are saved.',
            'ru' => 'Спасибо, что попробовали ассистента! 🙏 Бесплатный период закончился. Чтобы бот продолжил работать, оформите подписку — все настройки и данные сохранены.',
            'ar' => 'شكرًا لتجربتك المساعد! 🙏 انتهت الفترة التجريبية المجانية. للحفاظ على تشغيل البوت، يرجى الترقية — جميع إعداداتك وبياناتك محفوظة.',
            default => 'תודה שהתנסיתם בעוזר החכם! 🙏 תקופת הניסיון הסתיימה. כדי להשאיר את הבוט פעיל, שדרגו לחשבון בתשלום — כל ההגדרות והנתונים שלכם נשמרים.',
        };
    }

    // ─── Admin notice ─────────────────────────────────────────────────────────

    public function admin_notice(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $status = $this->status();
        if ( $status === 'active' ) {
            printf(
                '<div class="notice notice-info" dir="rtl"><p>%s</p></div>',
                esc_html( sprintf(
                    '⏳ ניסיון חינם פעיל: נותרו %d ימים ו-%d שיחות מתוך %d.',
                    $this->days_left(),
                    $this->conversations_left(),
                    self::TRIAL_CONVO_CAP
                ) )
            );
        } elseif ( $status === 'ended' ) {
            echo '<div class="notice notice-warning" dir="rtl"><p>'
                . '⚠️ תקופת הניסיון של הבוט הסתיימה — הבוט מושהה עד לשדרוג. כל ההגדרות והנתונים נשמרו.'
                . '</p></div>';
        }
    }
}
