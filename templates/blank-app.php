<?php
if (!defined('ABSPATH')) exit;
$is_login = is_page(absint(get_option('bb_login_page_id')));
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
<style>
html,body{margin:0!important;padding:0!important;top:0!important;background:#f7f9fc}html{margin-top:0!important}body.admin-bar{margin-top:0!important;padding-top:0!important}#wpadminbar{display:none!important}body:before,body:after{display:none!important}
/* Critical first-paint guard: keep raw form/button markup from flashing before the main stylesheet is parsed. */
body.business-board-blank-app{min-height:100vh;background:#f7f9fc}
body.business-board-blank-app .bb-shortcode-wrap,body.business-board-blank-app .bb-login-page{min-height:100vh}
</style>
</head>
<body <?php body_class('business-board-blank-app'); ?>>
<?php wp_body_open(); ?>
<?php
if ($is_login) {
    echo do_shortcode('[business_board_login]');
} else {
    echo do_shortcode('[business_board]');
}
?>
<?php wp_footer(); ?>
</body>
</html>
