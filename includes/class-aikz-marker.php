<?php
/**
 * Visueller Markierungs-Editor: beliebige Elemente einer Seite als KI-Inhalt kennzeichnen.
 *
 * @package ki-kennzeichnung
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AIKZ_Marker {

	const META_MARKS = '_aikz_marks';
	const OPTION_URL = 'aikz_url_marks';
	const REST_NS    = 'aikz/v1';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 100 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
		add_action( 'wp_footer', array( $this, 'render_editor_shell' ), 5 );
		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_action( 'aikz_settings_after_form', array( $this, 'render_tools' ) );
		add_action( 'admin_post_aikz_clear_marks', array( $this, 'handle_clear' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Aufräumen                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Zählt gesetzte Kennzeichnungen nach Quelle.
	 */
	public function counts() {
		global $wpdb;

		$media = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '1'",
				AIKZ_Plugin::META_FLAG
			)
		);

		$post_marks = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::META_MARKS
			)
		);

		$url_marks = get_option( self::OPTION_URL, array() );
		$url_marks = is_array( $url_marks ) ? count( $url_marks ) : 0;

		return array(
			'media'   => $media,
			'manual'  => $post_marks + $url_marks,
		);
	}

	public function render_tools() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$counts = $this->counts();
		?>
		<?php if ( isset( $_GET['aikz_cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Alle visuellen Markierungen wurden gelöscht.', 'ki-kennzeichnung' ); ?></p></div>
		<?php endif; ?>
		<hr>
		<h2 class="title"><?php esc_html_e( 'Übersicht und Aufräumen', 'ki-kennzeichnung' ); ?></h2>
		<p class="description" style="max-width:46em">
			<?php esc_html_e( 'Ein Hinweis kann aus zwei Quellen stammen. Wird ein Hinweis trotz Entfernen weiter angezeigt, ist meist noch die jeweils andere Quelle gesetzt – oder ein Cache liefert die alte Seite aus.', 'ki-kennzeichnung' ); ?>
		</p>

		<table class="widefat striped" style="max-width:46em">
			<tbody>
				<tr>
					<td><strong><?php esc_html_e( 'Mediathek', 'ki-kennzeichnung' ); ?></strong><br>
						<span class="description"><?php esc_html_e( 'Pro Datei gesetzt, gilt überall, wo das Bild eingebunden ist.', 'ki-kennzeichnung' ); ?></span></td>
					<td style="width:8em">
						<?php
						printf(
							/* translators: %d: number of marked media */
							esc_html( _n( '%d Medium', '%d Medien', $counts['media'], 'ki-kennzeichnung' ) ),
							(int) $counts['media']
						);
						?>
					</td>
					<td style="width:12em">
						<a class="button" href="<?php echo esc_url( admin_url( 'upload.php?mode=list&aikz_filter=ai' ) ); ?>"><?php esc_html_e( 'Anzeigen', 'ki-kennzeichnung' ); ?></a>
					</td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Visuelle Markierungen', 'ki-kennzeichnung' ); ?></strong><br>
						<span class="description"><?php esc_html_e( 'Im Frontend-Editor gesetzt, gilt nur auf der jeweiligen Seite.', 'ki-kennzeichnung' ); ?></span></td>
					<td>
						<?php
						printf(
							/* translators: %d: number of pages with manual marks */
							esc_html( _n( '%d Seite', '%d Seiten', $counts['manual'], 'ki-kennzeichnung' ) ),
							(int) $counts['manual']
						);
						?>
					</td>
					<td>
						<?php if ( $counts['manual'] ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Wirklich alle visuellen Markierungen löschen? Die Mediathek-Kennzeichnungen bleiben erhalten.', 'ki-kennzeichnung' ) ); ?>');">
								<input type="hidden" name="action" value="aikz_clear_marks">
								<?php wp_nonce_field( 'aikz_clear_marks' ); ?>
								<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Alle löschen', 'ki-kennzeichnung' ); ?></button>
							</form>
						<?php else : ?>
							<span class="description">—</span>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	public function handle_clear() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'aikz_clear_marks' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'ki-kennzeichnung' ) );
		}

		delete_option( self::OPTION_URL );
		delete_post_meta_by_key( self::META_MARKS );

		wp_safe_redirect( add_query_arg( 'aikz_cleared', '1', admin_url( 'options-general.php?page=ki-kennzeichnung' ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Kontext & Speicherung                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Ermittelt, zu welcher "Seite" Markierungen gehören.
	 * Einzelne Beiträge/Seiten → Post-Meta. Alles andere → Option nach Pfad.
	 */
	public function current_context() {
		if ( is_singular() ) {
			$id = (int) get_queried_object_id();
			if ( $id ) {
				return array(
					'type' => 'post',
					'id'   => $id,
					'key'  => 'post:' . $id,
				);
			}
		}

		$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '/';
		$path = '/' . trim( (string) $path, '/' );

		return array(
			'type' => 'url',
			'id'   => 0,
			'key'  => 'url:' . $path,
			'path' => $path,
		);
	}

	private function parse_key( $key ) {
		if ( 0 === strpos( $key, 'post:' ) ) {
			return array(
				'type' => 'post',
				'id'   => (int) substr( $key, 5 ),
				'key'  => $key,
			);
		}
		return array(
			'type' => 'url',
			'id'   => 0,
			'key'  => $key,
			'path' => substr( $key, 4 ),
		);
	}

	public function get_marks( $context ) {
		if ( 'post' === $context['type'] ) {
			$marks = get_post_meta( $context['id'], self::META_MARKS, true );
		} else {
			$all   = get_option( self::OPTION_URL, array() );
			$marks = isset( $all[ $context['key'] ] ) ? $all[ $context['key'] ] : array();
		}
		return is_array( $marks ) ? array_values( $marks ) : array();
	}

	public function save_marks( $context, $marks ) {
		$marks = $this->sanitize_marks( $marks );

		if ( 'post' === $context['type'] ) {
			if ( empty( $marks ) ) {
				delete_post_meta( $context['id'], self::META_MARKS );
			} else {
				update_post_meta( $context['id'], self::META_MARKS, $marks );
			}
			return $marks;
		}

		$all = get_option( self::OPTION_URL, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		if ( empty( $marks ) ) {
			unset( $all[ $context['key'] ] );
		} else {
			$all[ $context['key'] ] = $marks;
		}
		update_option( self::OPTION_URL, $all, false );

		return $marks;
	}

	private function sanitize_marks( $marks ) {
		$out       = array();
		$positions = array( 'top-left', 'top-right', 'bottom-left', 'bottom-right' );

		foreach ( (array) $marks as $mark ) {
			if ( empty( $mark['selector'] ) ) {
				continue;
			}

			// Selektoren dürfen kein Markup enthalten.
			$selector = trim( wp_strip_all_tags( (string) $mark['selector'] ) );
			if ( '' === $selector || strlen( $selector ) > 500 ) {
				continue;
			}

			$label = isset( $mark['label'] ) ? sanitize_text_field( $mark['label'] ) : '';
			if ( '' === $label ) {
				$label = AIKZ_Plugin::instance()->settings()['label'];
			}

			$out[] = array(
				'id'       => isset( $mark['id'] ) ? preg_replace( '/[^a-z0-9_-]/i', '', (string) $mark['id'] ) : uniqid( 'm' ),
				'selector' => $selector,
				'label'    => $label,
				'display'  => ( isset( $mark['display'] ) && 'caption' === $mark['display'] ) ? 'caption' : 'badge',
				'position' => ( isset( $mark['position'] ) && in_array( $mark['position'], $positions, true ) ) ? $mark['position'] : AIKZ_Plugin::instance()->settings()['position'],
			);
		}

		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* REST                                                               */
	/* ------------------------------------------------------------------ */

	public function register_routes() {
		register_rest_route(
			self::REST_NS,
			'/marks',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'rest_get' ),
					'permission_callback' => array( $this, 'rest_permission' ),
					'args'                => array(
						'context' => array( 'required' => true, 'type' => 'string' ),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'rest_save' ),
					'permission_callback' => array( $this, 'rest_permission' ),
					'args'                => array(
						'context' => array( 'required' => true, 'type' => 'string' ),
						'marks'   => array( 'required' => true, 'type' => 'array' ),
					),
				),
			)
		);
	}

	public function rest_permission( $request ) {
		$context = $this->parse_key( sanitize_text_field( (string) $request->get_param( 'context' ) ) );

		if ( 'post' === $context['type'] ) {
			return current_user_can( 'edit_post', $context['id'] );
		}
		return current_user_can( 'edit_theme_options' );
	}

	public function rest_get( $request ) {
		$context = $this->parse_key( sanitize_text_field( (string) $request->get_param( 'context' ) ) );
		return rest_ensure_response( array( 'marks' => $this->get_marks( $context ) ) );
	}

	public function rest_save( $request ) {
		$context = $this->parse_key( sanitize_text_field( (string) $request->get_param( 'context' ) ) );
		$marks   = $this->save_marks( $context, (array) $request->get_param( 'marks' ) );
		return rest_ensure_response( array( 'marks' => $marks, 'saved' => true ) );
	}

	/* ------------------------------------------------------------------ */
	/* Admin-Bar                                                          */
	/* ------------------------------------------------------------------ */

	public function can_edit_here() {
		if ( is_admin() || ! is_user_logged_in() ) {
			return false;
		}
		$context = $this->current_context();
		if ( 'post' === $context['type'] ) {
			return current_user_can( 'edit_post', $context['id'] );
		}
		return current_user_can( 'edit_theme_options' );
	}

	public function admin_bar( $bar ) {
		if ( ! $this->can_edit_here() || $this->is_frame() ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => 'aikz-editor',
				'title' => __( 'KI-Markierung', 'ki-kennzeichnung' ),
				'href'  => add_query_arg( 'aikz_editor', '1' ),
				'meta'  => array( 'title' => __( 'Inhalte dieser Seite visuell als KI-Inhalt kennzeichnen', 'ki-kennzeichnung' ) ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Modus-Erkennung                                                    */
	/* ------------------------------------------------------------------ */

	public function is_editor() {
		return ! empty( $_GET['aikz_editor'] ) && $this->can_edit_here(); // phpcs:ignore WordPress.Security.NonceVerification
	}

	public function is_frame() {
		return ! empty( $_GET['aikz_frame'] ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	public function body_class( $classes ) {
		if ( $this->is_frame() && $this->can_edit_here() ) {
			$classes[] = 'aikz-frame-mode';
		}
		return $classes;
	}

	/* ------------------------------------------------------------------ */
	/* Assets                                                             */
	/* ------------------------------------------------------------------ */

	public function enqueue() {
		$context = $this->current_context();
		$marks   = $this->get_marks( $context );
		$s       = AIKZ_Plugin::instance()->settings();

		// Runtime für Besucher – nur laden, wenn es Markierungen gibt.
		if ( ! empty( $marks ) ) {
			wp_enqueue_script( 'aikz-runtime', AIKZ_URL . 'assets/aikz-runtime.js', array(), AIKZ_Plugin::VERSION, true );
			wp_localize_script(
				'aikz-runtime',
				'aikzRuntime',
				array(
					'marks' => $marks,
					'mode'  => $s['mode'],
					'icon'  => $s['icon'] ? AIKZ_Plugin::instance()->icon_svg() : '',
				)
			);
		}

		if ( ! $this->can_edit_here() ) {
			return;
		}

		// Picker im iframe.
		if ( $this->is_frame() ) {
			wp_enqueue_style( 'aikz-picker', AIKZ_URL . 'assets/aikz-picker.css', array(), AIKZ_Plugin::VERSION );
			wp_enqueue_script( 'aikz-picker', AIKZ_URL . 'assets/aikz-picker.js', array(), AIKZ_Plugin::VERSION, true );
			wp_localize_script( 'aikz-picker', 'aikzPicker', array( 'origin' => home_url() ) );
			return;
		}

		// Editor-Shell.
		if ( $this->is_editor() ) {
			wp_enqueue_style( 'aikz-editor', AIKZ_URL . 'assets/aikz-editor.css', array(), AIKZ_Plugin::VERSION );
			wp_enqueue_script( 'aikz-editor', AIKZ_URL . 'assets/aikz-editor.js', array(), AIKZ_Plugin::VERSION, true );
			wp_localize_script(
				'aikz-editor',
				'aikzEditor',
				array(
					'restUrl'   => esc_url_raw( rest_url( self::REST_NS . '/marks' ) ),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
					'context'   => $context['key'],
					'frameUrl'  => add_query_arg(
						array(
							'aikz_frame' => '1',
							'aikz_ts'    => time(),
						),
						remove_query_arg( 'aikz_editor' )
					),
					'exitUrl'   => remove_query_arg( 'aikz_editor' ),
					'marks'     => $marks,
					'defaults'  => array(
						'label'    => $s['label'],
						'position' => $s['position'],
					),
					'i18n'      => array(
						'title'      => __( 'KI-Markierung', 'ki-kennzeichnung' ),
						'pick'       => __( 'Element auswählen', 'ki-kennzeichnung' ),
						'picking'    => __( 'Klicke ein Element an …', 'ki-kennzeichnung' ),
						'cancel'     => __( 'Abbrechen', 'ki-kennzeichnung' ),
						'save'       => __( 'Speichern', 'ki-kennzeichnung' ),
						'saved'      => __( 'Gespeichert', 'ki-kennzeichnung' ),
						'saveError'  => __( 'Speichern fehlgeschlagen', 'ki-kennzeichnung' ),
						'exit'       => __( 'Schließen', 'ki-kennzeichnung' ),
						'empty'      => __( 'Noch keine Markierungen auf dieser Seite.', 'ki-kennzeichnung' ),
						'label'      => __( 'Hinweistext', 'ki-kennzeichnung' ),
						'display'    => __( 'Darstellung', 'ki-kennzeichnung' ),
						'badge'      => __( 'Badge im Element', 'ki-kennzeichnung' ),
						'caption'    => __( 'Textzeile darunter', 'ki-kennzeichnung' ),
						'position'   => __( 'Position', 'ki-kennzeichnung' ),
						'add'        => __( 'Markierung hinzufügen', 'ki-kennzeichnung' ),
						'remove'     => __( 'Entfernen', 'ki-kennzeichnung' ),
						'marks'      => __( 'Markierungen', 'ki-kennzeichnung' ),
						'desktop'    => __( 'Desktop', 'ki-kennzeichnung' ),
						'tablet'     => __( 'Tablet', 'ki-kennzeichnung' ),
						'mobile'     => __( 'Smartphone', 'ki-kennzeichnung' ),
						'unsaved'    => __( 'Ungespeicherte Änderungen', 'ki-kennzeichnung' ),
						'topLeft'    => __( 'oben links', 'ki-kennzeichnung' ),
						'topRight'   => __( 'oben rechts', 'ki-kennzeichnung' ),
						'bottomLeft' => __( 'unten links', 'ki-kennzeichnung' ),
						'bottomRight'=> __( 'unten rechts', 'ki-kennzeichnung' ),
					),
				)
			);
		}
	}

	public function render_editor_shell() {
		if ( ! $this->is_editor() ) {
			return;
		}
		echo '<div id="aikz-editor-root" hidden></div>';
	}
}
