<?php
/**
 * Plugin Name: Event gallery creator
 * Description: Admin workflow for creating event image galleries from past The Events Calendar events.
 * Version: 0.1.0
 * Author: suseneprazene
 * Text Domain: event-gallery-creator
 */

if ( ! defined( 'ABSPATH' ) ) {
exit;
}

final class EGC_Plugin {
const PAGE_SLUG = 'event-gallery-creator';
const HIDDEN_META_KEY = '_egc_hidden_from_picker';

/** @var EGC_Folders_Adapter */
private $folders_adapter;

/** @var EGC_Gallery_Service */
private $gallery_service;

public static function init() {
$instance = new self();
$instance->hooks();
}

private function __construct() {
$this->folders_adapter = new EGC_Folders_Adapter();
$this->gallery_service = new EGC_Gallery_Service();
}

private function hooks() {
add_action( 'admin_menu', array( $this, 'register_menu' ) );
add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_link' ), 100 );
add_action( 'admin_post_egc_toggle_hidden', array( $this, 'handle_toggle_hidden' ) );
add_action( 'admin_post_egc_create_gallery', array( $this, 'handle_create_gallery' ) );
}

public function register_menu() {
add_management_page(
__( 'Event gallery creator', 'event-gallery-creator' ),
__( 'Event gallery creator', 'event-gallery-creator' ),
'upload_files',
self::PAGE_SLUG,
array( $this, 'render_page' )
);
}

public function add_admin_bar_link( $wp_admin_bar ) {
if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
return;
}

$wp_admin_bar->add_node(
array(
'id'    => 'egc_upload_event_gallery',
'title' => esc_html__( 'Nahrát novou galerii pro události', 'event-gallery-creator' ),
'href'  => $this->admin_url(),
)
);
}

private function event_post_type() {
if ( post_type_exists( 'tribe_events' ) ) {
return 'tribe_events';
}

return '';
}

private function get_event_timestamp( $event_id ) {
$start = get_post_meta( $event_id, '_EventStartDate', true );
if ( ! empty( $start ) ) {
$timestamp = strtotime( $start );
if ( false !== $timestamp ) {
return $timestamp;
}
}

$post = get_post( $event_id );
if ( ! $post ) {
return time();
}

$post_timestamp = strtotime( $post->post_date );
if ( false !== $post_timestamp ) {
return $post_timestamp;
}

return time();
}

private function build_folder_name( $event_id ) {
$title = get_the_title( $event_id );
$date  = wp_date( 'Y_m_d', $this->get_event_timestamp( $event_id ) );

return trim( sprintf( '%s %s', $date, wp_strip_all_tags( $title ) ) );
}

private function build_gallery_title( $event_id ) {
$title = get_the_title( $event_id );
$date  = wp_date( 'd. F Y', $this->get_event_timestamp( $event_id ) );

return trim( sprintf( '%s – %s', wp_strip_all_tags( $title ), $date ) );
}

private function admin_url( $args = array() ) {
$defaults = array( 'page' => self::PAGE_SLUG );
return add_query_arg( array_merge( $defaults, $args ), admin_url( 'tools.php' ) );
}

private function redirect_with_notice( $type, $message, $extra_args = array() ) {
$args = array_merge(
array(
'egc_notice_type' => $type,
'egc_notice'      => rawurlencode( $message ),
),
$extra_args
);

if ( ! empty( $args['event_id'] ) ) {
$args['_wpnonce'] = wp_create_nonce( 'egc_select_event_' . absint( $args['event_id'] ) );
}

wp_safe_redirect( $this->admin_url( $args ) );
exit;
}

private function is_valid_event( $event_id ) {
$event = get_post( $event_id );
if ( ! $event ) {
	return false;
}

$post_type = $this->event_post_type();
if ( '' === $post_type ) {
	return false;
}

return $event->post_type === $post_type;
}

private function current_show_hidden() {
return isset( $_REQUEST['show_hidden'] ) && '1' === sanitize_text_field( wp_unslash( $_REQUEST['show_hidden'] ) );
}

public function handle_toggle_hidden() {
if ( ! current_user_can( 'upload_files' ) ) {
wp_die( esc_html__( 'Insufficient permissions.', 'event-gallery-creator' ) );
}

check_admin_referer( 'egc_toggle_hidden' );

$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
$hidden   = isset( $_POST['hidden'] ) ? absint( $_POST['hidden'] ) : 0;
$show     = isset( $_POST['show_hidden'] ) ? absint( $_POST['show_hidden'] ) : 0;

if ( ! $event_id || ! current_user_can( 'edit_post', $event_id ) || ! $this->is_valid_event( $event_id ) ) {
$this->redirect_with_notice( 'error', __( 'Invalid event selected.', 'event-gallery-creator' ), array( 'show_hidden' => $show ) );
}

if ( 1 === $hidden ) {
update_post_meta( $event_id, self::HIDDEN_META_KEY, '1' );
$this->redirect_with_notice( 'success', __( 'Event hidden from picker.', 'event-gallery-creator' ), array( 'show_hidden' => $show ) );
}

delete_post_meta( $event_id, self::HIDDEN_META_KEY );
$this->redirect_with_notice( 'success', __( 'Event is visible in picker again.', 'event-gallery-creator' ), array( 'show_hidden' => $show ) );
}

private function normalize_uploaded_files( $files ) {
$normalized = array();
if ( empty( $files['name'] ) || ! is_array( $files['name'] ) ) {
return $normalized;
}

foreach ( $files['name'] as $index => $name ) {
if ( empty( $name ) ) {
continue;
}
$normalized[] = array(
'name'     => $files['name'][ $index ],
'type'     => $files['type'][ $index ],
'tmp_name' => $files['tmp_name'][ $index ],
'error'    => $files['error'][ $index ],
'size'     => $files['size'][ $index ],
);
}

return $normalized;
}

private function append_shortcode_block( $event_id, $gallery_id ) {
$event = get_post( $event_id );
if ( ! $event ) {
return new WP_Error( 'egc_missing_event', __( 'Selected event not found.', 'event-gallery-creator' ) );
}

$block = sprintf(
"<!-- wp:shortcode -->\n[print_gllr id=%d]\n<!-- /wp:shortcode -->",
$gallery_id
);

$content = rtrim( (string) $event->post_content );
if ( '' !== $content ) {
$content .= "\n\n";
}
$content .= $block;

$result = wp_update_post(
array(
'ID'           => $event_id,
'post_content' => $content,
),
true
);

if ( is_wp_error( $result ) ) {
return $result;
}

return true;
}

public function handle_create_gallery() {
if ( ! current_user_can( 'upload_files' ) ) {
wp_die( esc_html__( 'Insufficient permissions.', 'event-gallery-creator' ) );
}

check_admin_referer( 'egc_create_gallery' );

$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
$show     = isset( $_POST['show_hidden'] ) ? absint( $_POST['show_hidden'] ) : 0;

if ( ! $event_id || ! current_user_can( 'edit_post', $event_id ) || ! $this->is_valid_event( $event_id ) ) {
$this->redirect_with_notice( 'error', __( 'Invalid event selected.', 'event-gallery-creator' ), array( 'show_hidden' => $show ) );
}

if ( ! isset( $_FILES['egc_images'] ) ) {
$this->redirect_with_notice( 'error', __( 'Please select images to upload.', 'event-gallery-creator' ), array( 'event_id' => $event_id, 'show_hidden' => $show ) );
}

$files = $this->normalize_uploaded_files( $_FILES['egc_images'] );
if ( empty( $files ) ) {
$this->redirect_with_notice( 'error', __( 'Please select images to upload.', 'event-gallery-creator' ), array( 'event_id' => $event_id, 'show_hidden' => $show ) );
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$attachment_ids = array();
foreach ( $files as $file ) {
$_FILES['egc_single_image'] = $file;
$attachment_id              = media_handle_upload( 'egc_single_image', 0, array( 'post_parent' => $event_id ) );
if ( is_wp_error( $attachment_id ) ) {
unset( $_FILES['egc_single_image'] );
$this->redirect_with_notice( 'error', $attachment_id->get_error_message(), array( 'event_id' => $event_id, 'show_hidden' => $show ) );
}
$attachment_ids[] = (int) $attachment_id;
}
unset( $_FILES['egc_single_image'] );

$warnings = array();
$folder_name = $this->build_folder_name( $event_id );
$folder_result = $this->folders_adapter->create_and_assign( $folder_name, $attachment_ids );
if ( is_wp_error( $folder_result ) ) {
$warnings[] = $folder_result->get_error_message();
}

$gallery_title = $this->build_gallery_title( $event_id );
$gallery_id    = $this->gallery_service->create_gallery( $gallery_title, $attachment_ids );
if ( is_wp_error( $gallery_id ) ) {
$this->redirect_with_notice( 'error', $gallery_id->get_error_message(), array( 'event_id' => $event_id, 'show_hidden' => $show ) );
}

$append_result = $this->append_shortcode_block( $event_id, $gallery_id );
if ( is_wp_error( $append_result ) ) {
$this->redirect_with_notice( 'error', $append_result->get_error_message(), array( 'event_id' => $event_id, 'show_hidden' => $show ) );
}

$message = sprintf(
/* translators: %d: gallery ID */
__( 'Gallery created and appended to event content. Shortcode: [print_gllr id=%d]', 'event-gallery-creator' ),
$gallery_id
);

if ( ! empty( $warnings ) ) {
$message .= ' ' . implode( ' ', $warnings );
}

$this->redirect_with_notice(
'success',
$message,
array(
'event_id' => $event_id,
'show_hidden' => $show,
)
);
}

private function event_query( $show_hidden, $paged, $per_page ) {
$post_type = $this->event_post_type();
if ( '' === $post_type ) {
return new WP_Query( array( 'post__in' => array( 0 ) ) );
}

$current_datetime = current_datetime()->format( 'Y-m-d H:i:s' );
$meta_query       = array(
array(
'key'     => '_EventStartDate',
'value'   => $current_datetime,
'compare' => '<=',
'type'    => 'DATETIME',
),
);

if ( ! $show_hidden ) {
$meta_query[] = array(
'relation' => 'OR',
array(
'key'     => self::HIDDEN_META_KEY,
'compare' => 'NOT EXISTS',
),
array(
'key'     => self::HIDDEN_META_KEY,
'value'   => '1',
'compare' => '!=',
),
);
}

$args = array(
'post_type'      => $post_type,
'post_status'    => 'publish',
'posts_per_page' => $per_page,
'paged'          => $paged,
'meta_key'       => '_EventStartDate',
'orderby'        => 'meta_value_datetime',
'order'          => 'DESC',
'meta_query'     => $meta_query,
);

return new WP_Query( $args );
}

public function render_page() {
if ( ! current_user_can( 'upload_files' ) ) {
wp_die( esc_html__( 'Insufficient permissions.', 'event-gallery-creator' ) );
}

$event_post_type = $this->event_post_type();
$show_hidden     = $this->current_show_hidden();
$paged           = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
$selected_event  = 0;
if ( isset( $_GET['event_id'] ) ) {
	$candidate_event = absint( $_GET['event_id'] );
	$nonce           = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
	if ( $candidate_event > 0 && wp_verify_nonce( $nonce, 'egc_select_event_' . $candidate_event ) ) {
		$selected_event = $candidate_event;
	}
}
$events          = $this->event_query( $show_hidden, $paged, 20 );
?>
<div class="wrap">
<h1><?php echo esc_html__( 'Event gallery creator', 'event-gallery-creator' ); ?></h1>

<?php if ( isset( $_GET['egc_notice'] ) && isset( $_GET['egc_notice_type'] ) ) : ?>
<?php
$type    = sanitize_key( wp_unslash( $_GET['egc_notice_type'] ) );
$message = sanitize_text_field( rawurldecode( wp_unslash( $_GET['egc_notice'] ) ) );
$class   = 'notice-info';
if ( 'success' === $type ) {
$class = 'notice-success';
} elseif ( 'error' === $type ) {
$class = 'notice-error';
}
?>
<div class="notice <?php echo esc_attr( $class ); ?> is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
<?php endif; ?>

<?php if ( '' === $event_post_type ) : ?>
<div class="notice notice-error"><p><?php echo esc_html__( 'The Events Calendar post type (tribe_events) was not found.', 'event-gallery-creator' ); ?></p></div>
<?php else : ?>
<form method="get" action="<?php echo esc_url( admin_url( 'tools.php' ) ); ?>" style="margin-bottom:16px;">
<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
<?php if ( $selected_event ) : ?>
<input type="hidden" name="event_id" value="<?php echo esc_attr( $selected_event ); ?>" />
<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'egc_select_event_' . $selected_event ) ); ?>" />
<?php endif; ?>
<label>
<input type="checkbox" name="show_hidden" value="1" <?php checked( $show_hidden ); ?> />
<?php echo esc_html__( 'Show hidden events too', 'event-gallery-creator' ); ?>
</label>
<?php submit_button( __( 'Filter', 'event-gallery-creator' ), 'secondary', '', false ); ?>
</form>

<table class="widefat striped">
<thead>
<tr>
<th><?php echo esc_html__( 'Event', 'event-gallery-creator' ); ?></th>
<th><?php echo esc_html__( 'Date', 'event-gallery-creator' ); ?></th>
<th><?php echo esc_html__( 'Hidden', 'event-gallery-creator' ); ?></th>
<th><?php echo esc_html__( 'Actions', 'event-gallery-creator' ); ?></th>
</tr>
</thead>
<tbody>
<?php if ( ! empty( $events->posts ) ) : ?>
<?php foreach ( $events->posts as $event ) : ?>
<?php
$event_hidden = '1' === (string) get_post_meta( $event->ID, self::HIDDEN_META_KEY, true );
$event_date   = wp_date( 'j. F Y H:i', $this->get_event_timestamp( $event->ID ) );
$select_url   = $this->admin_url(
array(
'event_id'    => $event->ID,
'_wpnonce'    => wp_create_nonce( 'egc_select_event_' . $event->ID ),
'show_hidden' => $show_hidden ? 1 : 0,
'paged'       => $paged,
)
);
?>
<tr>
<td><?php echo esc_html( get_the_title( $event->ID ) ); ?></td>
<td><?php echo esc_html( $event_date ); ?></td>
<td><?php echo $event_hidden ? esc_html__( 'Yes', 'event-gallery-creator' ) : esc_html__( 'No', 'event-gallery-creator' ); ?></td>
<td>
<a href="<?php echo esc_url( $select_url ); ?>" class="button button-secondary"><?php echo esc_html__( 'Select event', 'event-gallery-creator' ); ?></a>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block; margin-left: 6px;">
<input type="hidden" name="action" value="egc_toggle_hidden" />
<input type="hidden" name="event_id" value="<?php echo esc_attr( $event->ID ); ?>" />
<input type="hidden" name="hidden" value="<?php echo $event_hidden ? '0' : '1'; ?>" />
<input type="hidden" name="show_hidden" value="<?php echo $show_hidden ? '1' : '0'; ?>" />
<?php wp_nonce_field( 'egc_toggle_hidden' ); ?>
<button type="submit" class="button button-secondary"><?php echo $event_hidden ? esc_html__( 'Unhide', 'event-gallery-creator' ) : esc_html__( 'Hide', 'event-gallery-creator' ); ?></button>
</form>
</td>
</tr>
<?php endforeach; ?>
<?php else : ?>
<tr><td colspan="4"><?php echo esc_html__( 'No past events found for this filter.', 'event-gallery-creator' ); ?></td></tr>
<?php endif; ?>
</tbody>
</table>

<?php
echo wp_kses_post(
paginate_links(
array(
'base'      => add_query_arg(
array(
'page'        => self::PAGE_SLUG,
'show_hidden' => $show_hidden ? 1 : 0,
'event_id'    => $selected_event,
'_wpnonce'    => $selected_event ? wp_create_nonce( 'egc_select_event_' . $selected_event ) : '',
'paged'       => '%#%',
),
admin_url( 'tools.php' )
),
'format'    => '',
'current'   => $paged,
'total'     => max( 1, (int) $events->max_num_pages ),
'type'      => 'list',
)
)
);
?>

<?php if ( $selected_event ) : ?>
<?php $event_obj = get_post( $selected_event ); ?>
<?php if ( $event_obj && $event_post_type === $event_obj->post_type ) : ?>
<hr />
<h2><?php echo esc_html__( 'Upload images and create gallery', 'event-gallery-creator' ); ?></h2>
<p><strong><?php echo esc_html__( 'Selected event:', 'event-gallery-creator' ); ?></strong> <?php echo esc_html( get_the_title( $selected_event ) ); ?></p>
<p><strong><?php echo esc_html__( 'Media folder label:', 'event-gallery-creator' ); ?></strong> <?php echo esc_html( $this->build_folder_name( $selected_event ) ); ?></p>
<p><strong><?php echo esc_html__( 'Gallery title:', 'event-gallery-creator' ); ?></strong> <?php echo esc_html( $this->build_gallery_title( $selected_event ) ); ?></p>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
<input type="hidden" name="action" value="egc_create_gallery" />
<input type="hidden" name="event_id" value="<?php echo esc_attr( $selected_event ); ?>" />
<input type="hidden" name="show_hidden" value="<?php echo $show_hidden ? '1' : '0'; ?>" />
<?php wp_nonce_field( 'egc_create_gallery' ); ?>
<input type="file" name="egc_images[]" multiple accept="image/*" required />
<?php submit_button( __( 'Upload and create gallery', 'event-gallery-creator' ) ); ?>
</form>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
</div>
<?php
wp_reset_postdata();
}
}

final class EGC_Gallery_Service {
const DEFAULT_LABEL = 'Default';

private function assign_default_label( $gallery_id, $post_type ) {
$taxonomies = get_object_taxonomies( $post_type, 'names' );
if ( empty( $taxonomies ) ) {
return true;
}

$preferred = array(
'category',
'gllr_category',
'gllr_categories',
'gallery_category',
'gallery_categories',
'post_tag',
'gllr_tag',
'gllr_tags',
'gallery_tag',
'gallery_tags',
);
$targets   = array();

foreach ( $preferred as $taxonomy ) {
if ( in_array( $taxonomy, $taxonomies, true ) && taxonomy_exists( $taxonomy ) ) {
$targets[] = $taxonomy;
}
}

if ( empty( $targets ) ) {
foreach ( $taxonomies as $taxonomy ) {
$taxonomy_object = get_taxonomy( $taxonomy );
if ( $taxonomy_object && ! $taxonomy_object->hierarchical ) {
$targets[] = $taxonomy;
}
}
}

foreach ( $targets as $taxonomy ) {
$result = wp_set_object_terms( $gallery_id, array( self::DEFAULT_LABEL ), $taxonomy, true );
if ( is_wp_error( $result ) ) {
return $result;
}
}

return true;
}

private function discover_post_type() {
global $gllr_options;

if ( is_array( $gllr_options ) && ! empty( $gllr_options['post_type_name'] ) ) {
$post_type_name = sanitize_key( $gllr_options['post_type_name'] );
if ( post_type_exists( $post_type_name ) ) {
	return $post_type_name;
}
}

$options = get_option( 'gllr_options' );
if ( is_array( $options ) && ! empty( $options['post_type_name'] ) ) {
$post_type_name = sanitize_key( $options['post_type_name'] );
if ( post_type_exists( $post_type_name ) ) {
	return $post_type_name;
}
}

$known = array( 'bws-gallery', 'gllr_gallery', 'gallery' );
foreach ( $known as $slug ) {
if ( post_type_exists( $slug ) ) {
return $slug;
}
}

global $wpdb;
$sql = "SELECT p.post_type FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE pm.meta_key = '_gallery_images' LIMIT 1";
$post_type = $wpdb->get_var( $sql );
if ( ! empty( $post_type ) && post_type_exists( $post_type ) ) {
return $post_type;
}

return new WP_Error( 'egc_gallery_post_type_not_found', __( 'BestWebSoft Gallery post type could not be discovered.', 'event-gallery-creator' ) );
}

public function create_gallery( $title, $attachment_ids ) {
if ( ! shortcode_exists( 'print_gllr' ) ) {
return new WP_Error( 'egc_gallery_shortcode_missing', __( 'Gallery by BestWebSoft shortcode [print_gllr] is not available.', 'event-gallery-creator' ) );
}

$post_type = $this->discover_post_type();
if ( is_wp_error( $post_type ) ) {
return $post_type;
}

$gallery_id = wp_insert_post(
array(
'post_type'   => $post_type,
'post_title'  => wp_strip_all_tags( $title ),
'post_status' => 'publish',
),
true
);

if ( is_wp_error( $gallery_id ) ) {
return $gallery_id;
}

$ids = array_map( 'intval', $attachment_ids );
update_post_meta( $gallery_id, '_gallery_images', implode( ',', $ids ) );

foreach ( $ids as $index => $attachment_id ) {
update_post_meta( $attachment_id, '_gallery_order_' . $gallery_id, $index + 1 );
}

$default_label_result = $this->assign_default_label( $gallery_id, $post_type );
if ( is_wp_error( $default_label_result ) ) {
return $default_label_result;
}

return (int) $gallery_id;
}
}

final class EGC_Folders_Adapter {
private function detect_taxonomy() {
if ( class_exists( 'WCP_Folders' ) && method_exists( 'WCP_Folders', 'get_custom_post_type' ) ) {
$taxonomy = WCP_Folders::get_custom_post_type( 'attachment' );
if ( ! empty( $taxonomy ) && taxonomy_exists( $taxonomy ) ) {
return $taxonomy;
}
}

if ( taxonomy_exists( 'media_folder' ) ) {
return 'media_folder';
}

return '';
}

public function create_and_assign( $folder_name, $attachment_ids ) {
$taxonomy = $this->detect_taxonomy();
if ( '' === $taxonomy ) {
return new WP_Error(
'egc_folder_integration_unavailable',
__( 'Folders by Premio integration was not detected. Gallery creation continued without folder assignment.', 'event-gallery-creator' )
);
}

$term = get_term_by( 'name', $folder_name, $taxonomy );
if ( ! $term || is_wp_error( $term ) ) {
$inserted = wp_insert_term( $folder_name, $taxonomy );
if ( is_wp_error( $inserted ) ) {
return new WP_Error(
'egc_folder_create_failed',
__( 'Folder assignment could not be completed automatically. Gallery creation continued.', 'event-gallery-creator' ) . ' ' . $inserted->get_error_message()
);
}
$term_id = (int) $inserted['term_id'];
} else {
$term_id = (int) $term->term_id;
}

foreach ( $attachment_ids as $attachment_id ) {
$assigned = wp_set_object_terms( (int) $attachment_id, array( $term_id ), $taxonomy, false );
if ( is_wp_error( $assigned ) ) {
return new WP_Error(
'egc_folder_assign_failed',
__( 'Some files were uploaded, but folder assignment was not fully completed. Gallery creation continued.', 'event-gallery-creator' )
);
}
}

return true;
}
}

EGC_Plugin::init();
