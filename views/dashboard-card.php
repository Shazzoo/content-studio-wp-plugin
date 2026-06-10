<?php

if (!defined('ABSPATH')) {
    exit;
}

$label = isset($label) ? $label : 'Total Articles';
$value = isset($value) ? $value : Content_Studio_Storage::count_posts();
$sync_action_url = admin_url('admin-post.php');
$sync_action = 'content_studio_sync';
$sync_nonce = wp_nonce_field($sync_action, '_wpnonce', true, false);
$sync_button = get_submit_button('Sync Articles', 'secondary content-studio-button content-studio-button--secondary', '', false);

?>

<div style=" background: #FFF;border: 1px solid #949494; padding: 18px 20px; display: flex; align-items: center; justify-content: space-between;">
    <div>
        <div style="color: #646970; font-size: 13px; margin-bottom: 8px;">
            <?php echo esc_html($label); ?>
        </div>
        <div style="font-size: 32px; line-height: 1; font-weight: 600;">
            <?php echo esc_html(number_format_i18n($value)); ?>
        </div>
    </div>

    <form action="<?php echo esc_url($sync_action_url); ?>" method="post">
        <input type="hidden" name="action" value="<?php echo esc_attr($sync_action); ?>" />
        <?php echo $sync_nonce; ?>
        <?php echo $sync_button; ?>
    </form>
</div>