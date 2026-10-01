<?php

if (!defined('ABSPATH')) {
    exit;
}

$label = isset($label) ? $label : 'Total Articles';
$value = isset($value) ? $value : Content_Studio_Storage::count_posts();
$status = Content_Studio_Admin_Settings::sync_status();
$sync_action_url = admin_url('admin-post.php');
$sync_action = 'content_studio_sync';
$sync_nonce = wp_nonce_field($sync_action, '_wpnonce', true, false);
$sync_button = get_submit_button('Sync Articles', 'secondary content-studio-button content-studio-button--secondary', '', false);

$format_time = static function ($timestamp) {
    $diff = human_time_diff($timestamp, time());

    return $timestamp <= time() ? sprintf('%s ago', $diff) : sprintf('in %s', $diff);
};

?>

<div style=" background: #FFF;border: 1px solid #949494; padding: 18px 20px; display: flex; align-items: center; justify-content: space-between; gap: 24px; flex-wrap: wrap;">
    <div style="display: flex; align-items: center; gap: 32px; flex-wrap: wrap;">
        <div>
            <div style="color: #646970; font-size: 13px; margin-bottom: 8px;">
                <?php echo esc_html($label); ?>
            </div>
            <div style="font-size: 32px; line-height: 1; font-weight: 600;">
                <?php echo esc_html(number_format_i18n($value)); ?>
            </div>
        </div>

        <dl class="content-studio-sync-status" style="display: grid; grid-template-columns: auto 1fr; gap: 4px 12px; margin: 0; font-size: 13px;">
            <dt style="color: #646970;">Last sync</dt>
            <dd style="margin: 0;">
                <?php if (null === $status['last_at']) : ?>
                    Not synced yet
                <?php elseif ('' !== $status['last_error']) : ?>
                    <span style="color: #d63638;"><?php echo esc_html(sprintf('Failed %s: %s', $format_time($status['last_at']), $status['last_error'])); ?></span>
                <?php else : ?>
                    <?php echo esc_html(sprintf('%s, %d article(s) added or updated', $format_time($status['last_at']), $status['last_count'])); ?>
                <?php endif; ?>
            </dd>

            <dt style="color: #646970;">Next sync</dt>
            <dd style="margin: 0;">
                <?php if (null === $status['next_at']) : ?>
                    Not scheduled yet; the plugin schedules it on the next page load
                <?php elseif ($status['cron_overdue']) : ?>
                    <span style="color: #b26200;"><?php echo esc_html(sprintf('Overdue by %s. WP-Cron is not running; see the README on using a real cron job.', human_time_diff($status['next_at'], time()))); ?></span>
                <?php else : ?>
                    <?php echo esc_html($status['next_at'] <= time() ? 'Due now' : $format_time($status['next_at'])); ?>
                <?php endif; ?>
            </dd>

            <?php if ('' !== $status['confirmation_error']) : ?>
                <dt style="color: #646970;">Publish confirmation</dt>
                <dd style="margin: 0;">
                    <span style="color: #d63638;"><?php echo esc_html(sprintf('Last attempt failed: %s', $status['confirmation_error'])); ?></span>
                </dd>
            <?php endif; ?>
        </dl>
    </div>

    <form action="<?php echo esc_url($sync_action_url); ?>" method="post">
        <input type="hidden" name="action" value="<?php echo esc_attr($sync_action); ?>" />
        <?php echo $sync_nonce; ?>
        <?php echo $sync_button; ?>
    </form>
</div>
