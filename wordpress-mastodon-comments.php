<?php
/*
Plugin Name: WordPress Mastodon Comments
Plugin URI: https://github.com/feiju12138/wordpress-mastodon-comments
Description: 替换WordPress原生评论区为Mastodon评论组件（支持自定义CDN、侧边栏配置Toot ID）
Version: v1.0.0
Author: feiju12138
Author URI: https://loli.fj.cn/
License: MIT
*/

// 安全防护：禁止直接访问文件
if (!defined('ABSPATH')) {
    exit;
}

// -------------------------- 后台全局设置页面 --------------------------
function mcr_add_settings_page() {
    add_options_page(
        'Mastodon Comments',         // 页面标题
        'Mastodon Comments',         // 菜单标题
        'manage_options',            // 权限
        'wordpress-mastodon-comments', // 菜单slug
        'mcr_render_settings_page'   // 渲染函数
    );
}
add_action('admin_menu', 'mcr_add_settings_page');

function mcr_register_settings() {
    register_setting('mcr_settings_group', 'mcr_mastodon_domain');
    register_setting('mcr_settings_group', 'mcr_mastodon_user');
    register_setting('mcr_settings_group', 'mcr_toot_id'); // 全局Toot ID（降级用）
    // 新增：注册CDN地址配置项，默认值为指定链接
    register_setting('mcr_settings_group', 'mcr_cdn_url');
}
add_action('admin_init', 'mcr_register_settings');

function mcr_render_settings_page() {
    ?>
    <div class="wrap">
        <h1>Mastodon Comments Settings</h1>
        <div style="background: #f0f8fb; padding: 10px 15px; border-left: 4px solid #2c7cb9; margin: 10px 0;">
            <strong>提示：</strong> 单篇文章可在编辑页右侧「Mastodon Toot ID」面板配置专属ID，优先级高于全局配置。
        </div>
        <form method="post" action="options.php">
            <?php
            settings_fields('mcr_settings_group');
            do_settings_sections('mcr_settings_group');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Mastodon 实例域名</th>
                    <td>
                        <input type="text" name="mcr_mastodon_domain" value="<?php echo esc_attr(get_option('mcr_mastodon_domain', 'mastodon.social')); ?>" class="regular-text" />
                        <p>缺省值为：mastodon.social</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Mastodon 用户名</th>
                    <td>
                        <input type="text" name="mcr_mastodon_user" value="<?php echo esc_attr(get_option('mcr_mastodon_user')); ?>" class="regular-text" />
                        <p>形如：feiju</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">全局 Mastodon Toot ID</th>
                    <td>
                        <input type="text" name="mcr_toot_id" value="<?php echo esc_attr(get_option('mcr_toot_id')); ?>" class="regular-text" />
                        <p>形如：116164221651686918</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Mastodon Comments JS CDN</th>
                    <td>
                        <input type="url" name="mcr_cdn_url" value="<?php echo esc_attr(get_option('mcr_cdn_url')); ?>" class="large-text" />
                        <p>缺省值为：https://cdn.jsdelivr.net/gh/feiju12138/mastodon-comments@3/dist/mastodon-comments.min.js</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    <?php
}

// -------------------------- 添加文章侧边栏自定义元框 --------------------------
function mcr_add_toot_id_meta_box() {
    add_meta_box(
        'mcr_toot_id_meta_box',
        'Mastodon Toot ID',
        'mcr_render_toot_id_meta_box',
        ['post', 'page'],
        'side',
        'default'
    );
}
add_action('add_meta_boxes', 'mcr_add_toot_id_meta_box');

function mcr_render_toot_id_meta_box($post) {
    $saved_toot_id = get_post_meta($post->ID, 'mcr_post_toot_id', true);
    wp_nonce_field('mcr_save_toot_id_meta', 'mcr_toot_id_nonce');
    ?>
    <label for="mcr_post_toot_id">Toot ID</label>
    <input type="text"
           id="mcr_post_toot_id"
           name="mcr_post_toot_id"
           value="<?php echo esc_attr($saved_toot_id); ?>"
           class="widefat"
           placeholder="输入当前文章的 Mastodon Toot ID"/>
    <p class="description">配置后将覆盖全局 Toot ID</p>
    <?php
}

function mcr_save_toot_id_meta($post_id) {
    if (!isset($_POST['mcr_toot_id_nonce']) || !wp_verify_nonce($_POST['mcr_toot_id_nonce'], 'mcr_save_toot_id_meta')) {
        return;
    }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }
    if (isset($_POST['mcr_post_toot_id'])) {
        $toot_id = sanitize_text_field($_POST['mcr_post_toot_id']);
        if (!empty($toot_id)) {
            update_post_meta($post_id, 'mcr_post_toot_id', $toot_id);
        } else {
            delete_post_meta($post_id, 'mcr_post_toot_id');
        }
    }
}
add_action('save_post', 'mcr_save_toot_id_meta');

// -------------------------- 替换默认评论区 --------------------------
function mcr_disable_default_comments($comment_template) {
    if (is_singular() && comments_open()) {
        return plugin_dir_path(__FILE__) . 'mastodon-comments-template.php';
    }
    return $comment_template;
}
add_filter('comments_template', 'mcr_disable_default_comments');

// -------------------------- 不再加载默认评论区的CSS --------------------------
function mcr_remove_default_comment_styles() {
    if (is_singular() && comments_open()) {
        wp_dequeue_style('wp-block-comments');
    }
}
add_action('wp_enqueue_scripts', 'mcr_remove_default_comment_styles', 99);

// -------------------------- 评论模板文件 --------------------------
function mcr_activate_plugin() {
    $template_path = plugin_dir_path(__FILE__) . 'mastodon-comments-template.php';
    if (!file_exists($template_path)) {
        $template_content = '<?php
// Mastodon评论模板
if (!defined(\'ABSPATH\')) exit;

// 获取配置
$mastodon_domain = esc_js(get_option(\'mcr_mastodon_domain\') ?: \'mastodon.social\');
$mastodon_user = esc_js(get_option(\'mcr_mastodon_user\'));
$cdn_url = esc_js(get_option(\'mcr_cdn_url\') ?: \'https://cdn.jsdelivr.net/gh/feiju12138/mastodon-comments@3/dist/mastodon-comments.min.js\');
$toot_id = esc_js(get_post_meta(get_the_ID(), \'mcr_post_toot_id\', true) ?: get_option(\'mcr_toot_id\'));

// 验证配置
if (empty(trim($mastodon_user)) || empty(trim($toot_id))) {
    echo \'<div class="mcr-error" style="padding: 20px; background: #fef0f0; border: 1px solid #fcc; color: #dc3232;">\';
    echo \'请先配置 Mastodon 用户名和 Mastodon Toot ID！<br/>\';
    echo \'1. 单篇文章：文章编辑页右侧编辑「Mastodon Toot ID」面板；<br/>\';
    echo \'2. 全局默认：<a href="\' . admin_url(\'options-general.php?page=wordpress-mastodon-comments\') . \'">后台设置</a>编辑「全局 Mastodon Toot ID」；\';
    echo \'</div>\';
    return;
}
?>

<!-- Mastodon评论区 -->
<div class="mastodon-comments-wrap">
    <!-- 1. 通过CDN引入组件 -->
    <script src="<?php echo $cdn_url; ?>"></script>

    <!-- 2. 创建挂载点 -->
    <div id="mastodon-comments"></div>

    <!-- 3. 初始化组件 -->
    <script>
      document.addEventListener("DOMContentLoaded", function() {
        initMastodonComments({
          MASTODON_DOMAIN: "<?php echo $mastodon_domain; ?>",
          MASTODON_USER: "<?php echo $mastodon_user; ?>",
          TOOT_ID: "<?php echo $toot_id; ?>"
        });
      });
    </script>
</div>';
        file_put_contents($template_path, $template_content);
    }
}
register_activation_hook(__FILE__, 'mcr_activate_plugin');
