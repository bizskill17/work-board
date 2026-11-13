<?php
/**
 * Plugin Name: Workboard
 * Description: Industry-neutral operational task management with Workspaces, Work Areas, user-based sharing, recurring tasks, overdue tracking and scoreboards.
 * Version: 4.18.177
 * Author: BizSkill
 * Text Domain: business-board
 */
if (!defined('ABSPATH')) exit;

final class BizSkill_Business_Board {
    const VERSION = '4.18.177';
    // Full dbDelta schema version. Small later columns use targeted ensure_* migrations.
    const DB_VERSION = '4.15.31';
    private static $instance = null;
    private $tables = [];
    private $network_tables = [];
    // Captures the most recent WhatsApp API result for diagnostics without changing legacy callers.
    private $last_whatsapp_result = null;

    public static function instance(){
        if(self::$instance===null) self::$instance=new self();
        return self::$instance;
    }

    private function __construct(){
        global $wpdb;
        $this->tables = [
            'workspaces'      => $wpdb->prefix.'bb_workspaces',
            'folders'         => $wpdb->prefix.'bb_folders',
            'folder_workspaces' => $wpdb->prefix.'bb_folder_workspaces',
            'notespaces'      => $wpdb->prefix.'bb_notespaces',
            'folder_notespaces' => $wpdb->prefix.'bb_folder_notespaces',
            'note_areas'      => $wpdb->prefix.'bb_note_areas',
            'note_cards'      => $wpdb->prefix.'bb_note_cards',
            'mathspaces'       => $wpdb->prefix.'bb_mathspaces',
            'math_areas'       => $wpdb->prefix.'bb_math_areas',
            'math_entries'     => $wpdb->prefix.'bb_math_entries',
            'work_areas'      => $wpdb->prefix.'bb_work_areas',
            'stage_master'     => $wpdb->prefix.'bb_stage_master',
            'labels'           => $wpdb->prefix.'bb_labels',
            'workspace_areas' => $wpdb->prefix.'bb_workspace_areas',
            'employees'       => $wpdb->prefix.'bb_employees',
            'parties'         => $wpdb->prefix.'bb_parties',
            'work_area_parties' => $wpdb->prefix.'bb_work_area_parties',
            'workspace_parties' => $wpdb->prefix.'bb_workspace_parties',
            'attendance_combos' => $wpdb->prefix.'bb_attendance_combos',
            'attendance_events' => $wpdb->prefix.'bb_attendance_events',
            'attendance_audit' => $wpdb->prefix.'bb_attendance_audit',
            'attendance_holidays' => $wpdb->prefix.'bb_attendance_holidays',
            'attendance_weekly_off_claims' => $wpdb->prefix.'bb_attendance_weekly_off_claims',
            'attendance_leaves' => $wpdb->prefix.'bb_attendance_leaves',
            'attendance_payroll_adjustments' => $wpdb->prefix.'bb_attendance_payroll_adjustments',
            'designations' => $wpdb->prefix.'bb_designations',
            'tasks'           => $wpdb->prefix.'bb_tasks',
            'checklists'      => $wpdb->prefix.'bb_checklists',
            'subtask_progress' => $wpdb->prefix.'bb_subtask_progress',
            'history'         => $wpdb->prefix.'bb_task_history',
            'score_archive'   => $wpdb->prefix.'bb_score_archive',
            'workspace_templates' => $wpdb->prefix.'bb_workspace_templates',
            'work_area_templates' => $wpdb->prefix.'bb_work_area_templates',
            'workspace_access' => $wpdb->prefix.'bb_workspace_access',
            'activity' => $wpdb->prefix.'bb_activity_log',
            'timesheet' => $wpdb->prefix.'bb_timesheet_entries',
            'callspace' => $wpdb->prefix.'bb_callspace_entries',
            'callspace_tasks' => $wpdb->prefix.'bb_callspace_tasks',
            'docspace' => $wpdb->prefix.'bb_docspace_entries',
            'recurrence_exceptions' => $wpdb->prefix.'bb_recurrence_exceptions',
            'notifications' => $wpdb->prefix.'bb_notifications',
            'attachments' => $wpdb->prefix.'bb_task_attachments',
            'template_versions' => $wpdb->prefix.'bb_template_versions',
            'departments' => $wpdb->prefix.'bb_org_departments',
            'employee_departments' => $wpdb->prefix.'bb_employee_departments',
            'workspace_departments' => $wpdb->prefix.'bb_workspace_departments',
            'entity_assignments' => $wpdb->prefix.'bb_entity_assignments',
            'task_remarks' => $wpdb->prefix.'bb_task_remarks',
            'goal_progress' => $wpdb->prefix.'bb_task_goal_progress',
            'task_reminders' => $wpdb->prefix.'bb_task_reminders',
            'task_overdue_reminders' => $wpdb->prefix.'bb_task_overdue_reminders',
            'reminder_delivery' => $wpdb->prefix.'bb_reminder_delivery',
        ];
        $this->network_tables = [
            'site_settings' => $wpdb->base_prefix.'bb_messaging_sites',
            'message_log' => $wpdb->base_prefix.'bb_message_log',
            'wallets' => $wpdb->base_prefix.'bb_wallets',
            'wallet_ledger' => $wpdb->base_prefix.'bb_wallet_ledger',
            'recharges' => $wpdb->base_prefix.'bb_wallet_recharges',
        ];
        register_activation_hook(__FILE__,[$this,'activate']);
        add_action('plugins_loaded',[$this,'maybe_upgrade']);
        add_action('admin_menu',[$this,'admin_menu']);
        add_action('network_admin_menu',[$this,'network_admin_menu']);
        add_filter('wp_handle_upload_prefilter',[$this,'enforce_site_storage_quota']);
        add_action('add_attachment',[$this,'clear_site_storage_cache']);
        add_action('delete_attachment',[$this,'clear_site_storage_cache']);
        add_action('wp_initialize_site',[$this,'initialize_new_site_storage'],20,1);
        // v4.13.20: allow the shared mobile shortcut/app name to be managed beside the common multisite icon.
        add_action('admin_init',[$this,'save_common_branding_app_name']);
        add_action('admin_footer',[$this,'common_site_icon_app_name_field']);
        add_action('bb_daily_history_cleanup',[$this,'automatic_history_cleanup']);
        add_action('bb_morning_task_notifications',[$this,'morning_task_notifications']);
        add_filter('cron_schedules',[$this,'cron_schedules']);
        add_action('bb_task_reminder_tick',[$this,'task_reminder_tick']);
        add_action('bb_network_message_cleanup',[$this,'network_message_cleanup']);
        add_action('bb_attendance_reverse_geocode_event',[$this,'attendance_reverse_geocode_event'],10,1);
        // Keep the reminder event scheduled even on UI-only upgrades where activate() is intentionally not rerun.
        add_action('init',[$this,'ensure_reminder_cron'],20);
        // Fallback catch-up: if WP/server cron is stale but Workboard receives traffic,
        // process the current site's reminder queue at shutdown. This does not replace
        // the real server cron; it prevents overdue reminders from silently stalling.
        add_action('shutdown',[$this,'maybe_run_reminder_fallback'],20);
        add_action('rest_api_init',[$this,'register_telegram_rest_route']);
        add_action('rest_api_init',[$this,'register_razorpay_rest_route']);
        add_action('rest_api_init',[$this,'register_whatsapp_rest_route']);
        add_action('admin_enqueue_scripts',[$this,'enqueue_admin']);
        add_action('send_headers',[$this,'send_frontend_nocache_headers']);
        add_shortcode('business_board',[$this,'shortcode']);
        add_action('admin_post_bb_export_report',[$this,'export_report']);
        add_action('admin_post_bb_export_backup',[$this,'export_backup']);
        add_action('admin_post_bb_restore_backup',[$this,'restore_backup']);
        add_action('admin_post_bb_export_workspace_package',[$this,'export_workspace_package']);
        add_action('admin_post_bb_export_visible_tasks',[$this,'export_visible_tasks']);
        add_action('admin_post_bb_export_activity_updates',[$this,'export_activity_updates']);
        add_action('admin_post_bb_export_notespace_excel',[$this,'export_notespace_excel']);
        add_action('admin_post_bb_export_math_area_pdf',[$this,'export_math_area_pdf']);
        foreach([
            'refresh_nonce','get_state','get_admin_heavy_state','get_task_activity','get_task_detail','save_workspace','reorder_workspaces','archive_workspace','unarchive_workspace','delete_workspace','restore_workspace','permanent_delete_workspace','save_folder','delete_folder','assign_folder_workspaces','assign_folder_contents','save_notespace','delete_notespace','reorder_notespaces','toggle_notespace_pin','save_note_area','delete_note_area','reorder_note_areas','save_note_card','delete_note_card','reorder_note_cards','move_note_card_area','save_mathspace','delete_mathspace','save_math_area','delete_math_area','reorder_math_areas','save_math_entry','reorder_math_entries','duplicate_math_entry','move_math_entry_area','delete_math_entry','bulk_upload_math_entries','save_work_area','duplicate_work_area','archive_work_area','unarchive_work_area','delete_work_area','restore_work_area','permanent_delete_work_area',
            'save_employee','delete_employee','save_party','delete_party','save_department','delete_department','save_designation','delete_designation','save_label','delete_label','save_attendance_combo','delete_attendance_combo','save_attendance_holiday','delete_attendance_holiday','bulk_save_attendance_holidays','claim_weekly_off','review_weekly_off_claim','save_leave_request','review_leave_request','delete_leave_request','save_attendance_settings','test_attendance_whatsapp','get_attendance_whatsapp_test_status','save_attendance_payroll_adjustment','get_attendance_month','punch_attendance','add_attendance_event','update_attendance_event','delete_attendance_event','delete_attendance_day','save_task','update_task_billing','delete_task','delete_task_history','archive_task','unarchive_task','toggle_task','save_org_settings','save_retention','purge_history',
            'save_workspace_template','update_workspace_template','delete_workspace_template','create_workspace_from_template','update_connected_template',
            'save_work_area_template','update_work_area_template','create_work_area_from_template','delete_work_area_template','bulk_archive_tasks','bulk_delete_tasks',
            'sync_workspace_from_template','detach_workspace_template','workspace_template_diff',
            'save_access','delete_access','pause_recurrence','resume_recurrence','skip_next_occurrence',
            'upload_attachment','delete_attachment','mark_notifications_read','save_notification_settings','test_meta_whatsapp','test_morning_pending_digest','save_site_messaging_settings','wallet_create_recharge','wallet_verify_recharge','get_message_log','save_telegram_settings','test_telegram','save_branding_settings','save_ai_settings','ai_simplify_task_text','ai_generate_workspace','ai_create_workspace','ai_generate_workspace_changes','ai_apply_workspace_changes',
            'get_report','get_daily_recurring_scoreboard','universal_search','goal_progress_list','goal_progress_add','goal_progress_update','goal_progress_delete','rollback_template_version','import_workspace_package','toggle_pin_task','toggle_task_focus','send_urgent_reminder','update_task_reminders','duplicate_task','duplicate_note_card','move_work_area','reorder_work_areas','reorder_tasks','move_task_work_area','toggle_subtask','save_entity_assignments','add_task_remark','set_task_status','update_task_field','add_inline_subtask','update_inline_subtask','delete_inline_subtask','reorder_inline_subtasks','get_timesheet_entries','get_timesheet_task_options','save_timesheet_entry','delete_timesheet_entry','get_callspace_entries','save_callspace_entry','delete_callspace_entry','get_docspace_entries','save_docspace_entry','delete_docspace_entry'
        ] as $a){
            add_action('wp_ajax_bb_'.$a,[$this,'ajax_'.$a]);
        }
        add_action('wp_ajax_nopriv_bb_front_login',[$this,'ajax_front_login']);
        add_action('wp_ajax_bb_front_logout',[$this,'ajax_front_logout']);
        add_action('admin_init',[$this,'block_business_board_backend']);
        add_filter('show_admin_bar',[$this,'hide_business_board_admin_bar']);
        add_shortcode('business_board_login',[$this,'login_shortcode']);
        add_action('init',[$this,'register_frontend_routes']);
        add_filter('query_vars',[$this,'frontend_query_vars']);
        // v4.14.40: hard fallback for Workboard friendly URLs. Some multisite/server
        // setups can serve stale rewrite rules after a network plugin update, which
        // made valid routes such as /dashboard/ return the theme's 404 page. Route
        // these paths directly during request parsing so they do not depend solely
        // on the persisted rewrite table.
        add_action('parse_request',[$this,'fallback_frontend_path_router'],1);
        add_filter('template_include',[$this,'frontend_blank_template'],PHP_INT_MAX);
        add_action('template_redirect',[$this,'frontend_no_cache'],0);
        add_action('wp_footer',[$this,'notespace_excel_footer_fallback'],99);
        // v4.13.17: make the shared Workboard logo the mobile shortcut/home-screen icon.
        add_action('wp_head',[$this,'frontend_shortcut_icon_meta'],999);
        add_action('template_redirect',[$this,'serve_frontend_manifest'],1);
    }

    private function is_workboard_frontend_request(){
        if(is_admin()) return false;
        $path=(string)parse_url($_SERVER['REQUEST_URI'] ?? '',PHP_URL_PATH);
        if(is_page(absint(get_option('bb_workspaces_page_id'))) || is_page(absint(get_option('bb_pending_tasks_page_id'))) || is_page(absint(get_option('bb_labels_page_id'))) || strpos($path,'/workspaces/')!==false || strpos($path,'/pending-tasks/')!==false || strpos($path,'/pending-billing/')!==false || strpos($path,'/billed-master/')!==false || strpos($path,'/labels/')!==false || strpos($path,'/notespaces/')!==false || strpos($path,'/mathspaces/')!==false || strpos($path,'/activity-log/')!==false || strpos($path,'/daily-activity/')!==false || strpos($path,'/callspace/')!==false || strpos($path,'/docspace/')!==false || strpos($path,'/focus/')!==false) return true;
        if(get_query_var('bb_front_route')) return true;
        return false;
    }

    private function shortcut_icon_url($size=192){
        $size=(int)$size;
        if(!in_array($size,[180,192,512],true)) $size=192;
        return plugins_url('assets/icons/workboard-shortcut-'.$size.'.png',__FILE__);
    }

    private function shortcut_app_name(){
        $name=trim((string)get_site_option('bb_workboard_app_name','Workboard'));
        if($name==='') $name='Workboard';
        return $name;
    }

    private function site_storage_limit_mb(){
        $limit=(int)get_option('bb_storage_limit_mb',0);
        if($limit<=0){ $limit=1024; update_option('bb_storage_limit_mb',$limit,false); }
        return $limit;
    }

    public function clear_site_storage_cache(){
        delete_transient('bb_storage_used_bytes_cache');
    }

    public function initialize_new_site_storage($new_site){
        if(!is_multisite() || empty($new_site->blog_id)) return;
        switch_to_blog((int)$new_site->blog_id);
        if(get_option('bb_storage_limit_mb')===false) update_option('bb_storage_limit_mb',1024,false);
        update_option('blog_upload_space',1024,false);
        restore_current_blog();
    }

    private function site_storage_used_bytes($force=false){
        if(!$force){
            $cached=get_transient('bb_storage_used_bytes_cache');
            if($cached!==false) return max(0,(int)$cached);
        }
        $upload=wp_upload_dir(); $dir=(string)($upload['basedir']??''); $bytes=0;
        if($dir && is_dir($dir)){
            if(function_exists('get_dirsize')){ $v=get_dirsize($dir); if(is_numeric($v)) $bytes=max(0,(int)$v); }
            else {
                try{
                    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS));
                    foreach($it as $f){ if($f->isFile()) $bytes+=(int)$f->getSize(); }
                }catch(Exception $e){ $bytes=0; }
            }
        }
        set_transient('bb_storage_used_bytes_cache',$bytes,5*MINUTE_IN_SECONDS);
        return $bytes;
    }

    private function storage_display($bytes){
        $bytes=max(0,(float)$bytes);
        if($bytes>=GB_IN_BYTES) return number_format($bytes/GB_IN_BYTES,2).' GB';
        return number_format($bytes/MB_IN_BYTES,2).' MB';
    }

    private function current_site_storage_state($force=false){
        $limit_mb=$this->site_storage_limit_mb(); $limit_bytes=$limit_mb*MB_IN_BYTES;
        $used=$this->site_storage_used_bytes($force); $balance=max(0,$limit_bytes-$used);
        $percent=$limit_bytes>0?min(100,round(($used/$limit_bytes)*100,1)):100;
        return [
            'limitMb'=>$limit_mb,'limitBytes'=>$limit_bytes,'usedBytes'=>$used,'balanceBytes'=>$balance,'percent'=>$percent,
            'totalLabel'=>$this->storage_display($limit_bytes),'usedLabel'=>$this->storage_display($used),'balanceLabel'=>$this->storage_display($balance),
            'isFull'=>$used >= $limit_bytes ? 1 : 0
        ];
    }

    public function enforce_site_storage_quota($file){
        if(!is_array($file) || !empty($file['error'])) return $file;
        $incoming=max(0,(int)($file['size']??0)); if($incoming<=0) return $file;
        $state=$this->current_site_storage_state(true);
        if(((int)$state['usedBytes']+$incoming)>(int)$state['limitBytes']){
            $file['error']='Site storage limit reached. Please contact your administrator to increase your site storage.';
        }
        return $file;
    }

    public function save_common_branding_app_name(){
        if(!is_network_admin() || !current_user_can('manage_network_options')) return;
        if(($_SERVER['REQUEST_METHOD'] ?? '')!=='POST' || !isset($_POST['bb_workboard_app_name'])) return;
        if(empty($_POST['bb_workboard_app_name_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bb_workboard_app_name_nonce'])),'bb_workboard_app_name_save')) return;
        $name=trim(sanitize_text_field(wp_unslash($_POST['bb_workboard_app_name'])));
        if($name==='') $name='Workboard';
        // Keep the launcher/app name concise. Mobile launchers truncate long labels anyway.
        if(function_exists('mb_substr')) $name=mb_substr($name,0,40); else $name=substr($name,0,40);
        update_site_option('bb_workboard_app_name',$name);
    }

    public function common_site_icon_app_name_field(){
        if(!is_network_admin() || !current_user_can('manage_network_options')) return;
        if(sanitize_key($_GET['page'] ?? '')!=='bizskill-common-site-icon') return;
        $name=$this->shortcut_app_name();
        $nonce=wp_create_nonce('bb_workboard_app_name_save');
        ?>
        <script id="bb-common-icon-app-name">
        (function(){
          function addField(){
            if(document.getElementById('bb-workboard-app-name-wrap')) return;
            var submits=[].slice.call(document.querySelectorAll('input[type=submit],button[type=submit]'));
            var save=submits.find(function(el){return /save common icon/i.test((el.value||el.textContent||''));}) || submits[0];
            if(!save) return;
            var form=save.closest('form'); if(!form) return;
            var wrap=document.createElement('div');
            wrap.id='bb-workboard-app-name-wrap';
            wrap.style.margin='22px 0 10px';
            wrap.innerHTML='<label for="bb_workboard_app_name" style="display:block;font-weight:600;margin-bottom:7px">Shortcut / App Name</label>'+
              '<input type="text" id="bb_workboard_app_name" name="bb_workboard_app_name" maxlength="40" value=<?php echo wp_json_encode($name); ?> style="width:350px;max-width:100%;" />'+
              '<input type="hidden" name="bb_workboard_app_name_nonce" value=<?php echo wp_json_encode($nonce); ?> />'+
              '<p class="description" style="margin-top:6px">This is the name shown below the mobile shortcut/app icon. Example: Workboard. Change it here any time in future.</p>';
            var p=save.parentElement;
            if(p && p.parentElement) p.parentElement.insertBefore(wrap,p); else form.insertBefore(wrap,save);
          }
          if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',addField); else addField();
        })();
        </script>
        <?php
    }

    public function frontend_shortcut_icon_meta(){
        if(!$this->is_workboard_frontend_request()) return;
        // v4.13.18: use real square PNGs. Chrome's Add-to-home-screen dialog may ignore
        // wide uploaded logos and fall back to a letter tile, especially when another
        // WordPress/theme favicon is also present. Output these tags late in wp_head so
        // Workboard's explicit icon wins on Workboard routes.
        $icon180=$this->shortcut_icon_url(180);
        $icon192=$this->shortcut_icon_url(192);
        $icon512=$this->shortcut_icon_url(512);
        $manifest=add_query_arg(['bb_workboard_manifest'=>'1','v'=>self::VERSION],home_url('/'));
        echo "\n<link rel=\"shortcut icon\" type=\"image/png\" href=\"".esc_url($icon192)."?v=".esc_attr(self::VERSION)."\">\n";
        echo "<link rel=\"icon\" type=\"image/png\" sizes=\"192x192\" href=\"".esc_url($icon192)."?v=".esc_attr(self::VERSION)."\">\n";
        echo "<link rel=\"icon\" type=\"image/png\" sizes=\"512x512\" href=\"".esc_url($icon512)."?v=".esc_attr(self::VERSION)."\">\n";
        echo "<link rel=\"apple-touch-icon\" sizes=\"180x180\" href=\"".esc_url($icon180)."?v=".esc_attr(self::VERSION)."\">\n";
        echo "<link rel=\"manifest\" href=\"".esc_url($manifest)."\">\n";
        echo "<meta name=\"theme-color\" content=\"#102746\">\n";
        echo "<meta name=\"apple-mobile-web-app-capable\" content=\"yes\">\n";
        echo "<meta name=\"apple-mobile-web-app-status-bar-style\" content=\"default\">\n";
        $app_name=$this->shortcut_app_name();
        echo "<meta name=\"application-name\" content=\"".esc_attr($app_name)."\">\n";
        echo "<meta name=\"apple-mobile-web-app-title\" content=\"".esc_attr($app_name)."\">\n";
        echo "<script>document.title=".wp_json_encode($app_name).";</script>\n";
    }

    public function serve_frontend_manifest(){
        if(empty($_GET['bb_workboard_manifest'])) return;
        $app_name=$this->shortcut_app_name();
        $start=trailingslashit(home_url('/')).'workspaces/';
        $manifest=[
            'name'=>$app_name,
            'short_name'=>$app_name,
            'start_url'=>$start,
            'scope'=>trailingslashit(home_url('/')),
            'display'=>'standalone',
            'background_color'=>'#ffffff',
            'theme_color'=>'#102746',
            'icons'=>[
                ['src'=>$this->shortcut_icon_url(192).'?v='.rawurlencode(self::VERSION),'sizes'=>'192x192','type'=>'image/png','purpose'=>'any'],
                ['src'=>$this->shortcut_icon_url(512).'?v='.rawurlencode(self::VERSION),'sizes'=>'512x512','type'=>'image/png','purpose'=>'any maskable'],
            ],
        ];
        nocache_headers();
        header('Content-Type: application/manifest+json; charset=utf-8');
        echo wp_json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Force every Workboard-linked account to authenticate again.
     *
     * We invalidate WordPress session tokens server-side, so stale browser cookies,
     * React state and cached nonces cannot keep an old Workboard session alive after
     * a plugin activation/update. This is deliberately always-on for version changes.
     */
    private function force_workboard_users_relogin($reason='plugin_update'){
        global $wpdb;
        if(!class_exists('WP_Session_Tokens')){
            $session_file=ABSPATH.WPINC.'/class-wp-session-tokens.php';
            if(file_exists($session_file)) require_once $session_file;
        }
        if(!class_exists('WP_Session_Tokens')) return;

        $user_ids=[];
        foreach(['employees','parties','departments'] as $key){
            $table=$this->tables[$key]??'';
            if(!$table) continue;
            if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table) continue;
            $ids=$wpdb->get_col("SELECT DISTINCT wp_user_id FROM {$table} WHERE wp_user_id IS NOT NULL AND wp_user_id > 0");
            foreach((array)$ids as $id) $user_ids[(int)$id]=true;
        }

        // Administrators can use the Workboard frontend even when they are not in User Master.
        foreach((array)get_users(['role'=>'administrator','fields'=>'ID']) as $id) $user_ids[(int)$id]=true;
        if(is_multisite()){
            foreach((array)get_super_admins() as $login){
                $u=get_user_by('login',$login);
                if($u) $user_ids[(int)$u->ID]=true;
            }
        }

        foreach(array_keys($user_ids) as $uid){
            if($uid>0) WP_Session_Tokens::get_instance($uid)->destroy_all();
        }

        update_option('bb_last_forced_relogin_at',current_time('mysql'));
        update_option('bb_last_forced_relogin_version',self::VERSION);
        update_option('bb_last_forced_relogin_reason',sanitize_key($reason));
    }

    /**
     * Periodic Workboard re-authentication; version changes never reset sessions.
     * The interval starts when this policy is first installed, then runs every 72 hours.
     */
    private function maybe_require_three_day_login(){
        $now=time();
        $last=(int)get_option('bb_relogin_cycle_timestamp',0);
        if($last<=0){
            add_option('bb_relogin_cycle_timestamp',$now,'','no');
            return;
        }
        if(($now-$last)<3*DAY_IN_SECONDS) return;
        // Advance the interval before destroying sessions, preventing repeat resets.
        update_option('bb_relogin_cycle_timestamp',$now,false);
        $this->force_workboard_users_relogin('three_day_interval');
    }

    public function maybe_upgrade(){
        // Re-login is time-based and independent of plugin updates.
        $this->maybe_require_three_day_login();
        // Keep schema upgrades separate from ordinary plugin/UI releases.
        // Previously every version bump called activate(), rerunning all dbDelta statements and migrations
        // on the first frontend request. On larger sites this could exhaust PHP/nginx time limits and cause 504s.
        $old_db_version=(string)get_option('bb_db_version','');
        $old_plugin_version=(string)get_option('bb_plugin_version',$old_db_version);

        if($old_db_version==='' || version_compare($old_db_version,self::DB_VERSION,'<')){
            $this->activate();
        }

        // Multiple task reminders use a small dedicated table. This is a targeted CREATE TABLE
        // and does not rerun the full Workboard dbDelta migration on UI/plugin updates.
        $this->ensure_task_reminders_table();
        $this->ensure_task_overdue_reminders_table();
        $this->ensure_reminder_delivery_table();
        $this->ensure_pending_performance_indexes();
        $this->ensure_math_tables();
        // v4.15.52: Supervisors can live-punch assigned employees. Keep attendance
        // columns repaired independently from the full dbDelta upgrade.
        $this->ensure_attendance_live_punch_schema();
        $this->ensure_attendance_leave_table();
        $this->ensure_task_billing_columns();
        // v4.18.168: task Goals (target count) + progress updates.
        $this->ensure_goal_schema();
        // v4.16.133: task-level Sunday reminder permission (off by default).
        $this->ensure_task_sunday_reminder_column();
        $this->ensure_party_share_schema();
        // v4.16.102: optional Time Sheet module with Daily Activity entries.
        $this->ensure_timesheet_table();
        // v4.18.0: communication and physical-document registers.
        $this->ensure_callspace_docspace_tables();
        // v4.14.31: Note Areas can optionally declare that Notes require a date.
        $this->ensure_note_area_date_columns();
        // v4.14.32: Store the actual applicable date on each Note, not on the Note Area.
        $this->ensure_note_card_date_column();
        // v4.14.34: Work Areas can require a remark for every task workflow/status update.
        $this->ensure_work_area_update_remarks_column();

        // v4.13.0: network-level messaging, wallet, recharge and immutable message-log tables.
        $this->ensure_network_messaging_tables();
        if($old_plugin_version !== self::VERSION || !get_option('bb_messaging_site_initialized',0)) $this->ensure_current_site_messaging_config();
        $this->ensure_network_message_cleanup_cron();

        // Scheduled reminders use their own Utility template. Older builds had the sender code
        // but never created/enabled these options in Settings, so reminder WhatsApp could be skipped.
        if(get_option('bb_meta_tpl_reminder')===false) update_option('bb_meta_tpl_reminder','workboard_task_reminder');
        if(get_option('bb_meta_tpl_reminder_enabled')===false) update_option('bb_meta_tpl_reminder_enabled',1);
        // v4.14.07: separate manual Urgent Reminder and automatic Due Reminder templates.
        if(get_option('bb_meta_tpl_due_reminder')===false) update_option('bb_meta_tpl_due_reminder','workboard_due_reminder');
        if(get_option('bb_meta_tpl_due_reminder_enabled')===false) update_option('bb_meta_tpl_due_reminder_enabled',1);
        if(get_option('bb_meta_tpl_morning')===false) update_option('bb_meta_tpl_morning','workboard_pending_morningreminder');
        // v4.14.45: Meta template renamed to the approved morning reminder name.
        // Migrate only the previous Workboard default; preserve any other custom template name.
        $morning_tpl=(string)get_option('bb_meta_tpl_morning','');
        if($morning_tpl==='' || $morning_tpl==='workboard_pending_tasks_reminder') update_option('bb_meta_tpl_morning','workboard_pending_morningreminder');
        if(get_option('bb_meta_tpl_morning_enabled')===false) update_option('bb_meta_tpl_morning_enabled',1);
        if(get_option('bb_notify_morning_pending')===false) update_option('bb_notify_morning_pending',1);

        if(get_option('bb_storage_limit_mb')===false) update_option('bb_storage_limit_mb',1024,false);

        if($old_plugin_version !== self::VERSION){
            // v4.13.15: retire the three legacy morning messages in favour of one grouped digest.
            update_option('bb_notify_due_soon',0);
            update_option('bb_notify_overdue',0);
            update_option('bb_notify_daily_summary',0);
            update_option('bb_meta_tpl_due_enabled',0);
            update_option('bb_meta_tpl_overdue_enabled',0);
            update_option('bb_meta_tpl_daily_enabled',0);
            update_option('bb_notify_morning_pending',1);
            update_option('bb_meta_tpl_morning_enabled',1);
            // Keep user-selected models, but move the original v4.11.0 default forward only when untouched.
            if((string)get_option('bb_ai_model','')==='gpt-5' && version_compare($old_plugin_version,'4.11.1','<')) update_option('bb_ai_model','gpt-5.6');
            // v4.11.49: move only untouched legacy WhatsApp template defaults to the new neutral names.
            $tpl_migrations=[
                'bb_meta_tpl_assignment'=>['workboard_task_assigned_utility','workboard_task_assigned'],
                'bb_meta_tpl_update'=>['workboard_task_updated_utility','workboard_task_updated'],
                'bb_meta_tpl_due'=>['workboard_task_due_utility','workboard_task_due'],
                'bb_meta_tpl_overdue'=>['workboard_task_overdue_utility','workboard_task_overdue'],
                'bb_meta_tpl_daily'=>['workboard_daily_summary_utility','workboard_pending_task_summary'],
            ];
            foreach($tpl_migrations as $option=>$pair){ if((string)get_option($option,$pair[0])===$pair[0]) update_option($option,$pair[1]); }
            // v4.15.70: sharing now belongs to Note Areas and Math Areas, with granular CRUD permissions.
            if(version_compare($old_plugin_version,'4.15.70','<')) $this->migrate_v41570_area_sharing_permissions();
            // v4.14.07: the old shared urgent template is retired. Manual urgent and automatic due reminders now use separate templates.
            $old_urgent=(string)get_option('bb_meta_tpl_urgent','workboard_urgentmessage');
            if(in_array($old_urgent,['workboard_urgent_update','workboard_urgentmessage'],true)) update_option('bb_meta_tpl_urgent','workboard_urgent_reminder');
            if(get_option('bb_meta_tpl_due_reminder')===false || in_array((string)get_option('bb_meta_tpl_due_reminder',''),['','workboard_urgentmessage'],true)) update_option('bb_meta_tpl_due_reminder','workboard_due_reminder');
            if(get_option('bb_meta_tpl_due_reminder_enabled')===false) update_option('bb_meta_tpl_due_reminder_enabled',1);
            update_option('bb_plugin_version',self::VERSION);
        }
    }

    private function ensure_task_billing_columns(){
        global $wpdb; $table=$this->tables['tasks'];
        $cols=$wpdb->get_col("DESC {$table}",0);
        if(!in_array('is_billable',$cols,true)) $wpdb->query("ALTER TABLE {$table} ADD COLUMN is_billable tinyint(1) DEFAULT 0 AFTER estimated_minutes");
        if(!in_array('billable_amount',$cols,true)) $wpdb->query("ALTER TABLE {$table} ADD COLUMN billable_amount decimal(15,2) DEFAULT NULL AFTER is_billable");
        if(!in_array('billed_amount',$cols,true)) $wpdb->query("ALTER TABLE {$table} ADD COLUMN billed_amount decimal(15,2) DEFAULT NULL AFTER billable_amount");
        if(!in_array('billing_updated_at',$cols,true)) $wpdb->query("ALTER TABLE {$table} ADD COLUMN billing_updated_at datetime DEFAULT NULL AFTER billed_amount");
    }

    private function ensure_goal_schema($force=false){
        global $wpdb;
        if(!$force && (string)get_option('bb_goal_schema','')==='3') return;
        $table=$this->tables['tasks']; $gp=$this->tables['goal_progress']??'';
        if(!$table || !$gp || $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$table))!==$table) return;
        $cols=$wpdb->get_col("DESC {$table}",0);
        if(!in_array('goal_target',$cols,true)) $wpdb->query("ALTER TABLE {$table} ADD COLUMN goal_target int unsigned NOT NULL DEFAULT 0");
        if(!in_array('goal_unit',$cols,true)) $wpdb->query("ALTER TABLE {$table} ADD COLUMN goal_unit varchar(40) NOT NULL DEFAULT ''");
        $charset=$wpdb->get_charset_collate();
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$gp} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            task_id bigint(20) unsigned NOT NULL,
            occurrence_key varchar(32) NOT NULL DEFAULT 'one',
            employee_id bigint(20) unsigned DEFAULT NULL,
            amount int NOT NULL DEFAULT 1,
            remark text NULL,
            created_at datetime NULL DEFAULT NULL,
            updated_at datetime NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY task_occurrence (task_id,occurrence_key),
            KEY employee_id (employee_id)
        ) {$charset}");
        $ok_cols=in_array('goal_target',$wpdb->get_col("DESC {$table}",0),true);
        $ok_tbl=$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$gp))===$gp;
        if($ok_tbl){
            // v4.18.172: each progress entry remembers the Goal that applied when it was made.
            $gcols=$wpdb->get_col("DESC {$gp}",0);
            if(!in_array('goal_target',$gcols,true)){
                $wpdb->query("ALTER TABLE {$gp} ADD COLUMN goal_target int unsigned NOT NULL DEFAULT 0 AFTER amount");
                $wpdb->query("ALTER TABLE {$gp} ADD COLUMN goal_unit varchar(40) NOT NULL DEFAULT '' AFTER goal_target");
                $wpdb->query("UPDATE {$gp} g JOIN {$table} t ON t.id=g.task_id SET g.goal_target=t.goal_target,g.goal_unit=t.goal_unit WHERE g.goal_target=0");
            }
            $ok_tbl=in_array('goal_target',$wpdb->get_col("DESC {$gp}",0),true);
        }
        if($ok_cols && $ok_tbl){ update_option('bb_goal_schema','3'); return true; }
        update_option('bb_goal_schema_error',$wpdb->last_error?:'unknown');
        return false;
    }

    private function ensure_task_sunday_reminder_column(){
        global $wpdb; $table=$this->tables['tasks'];
        if(!$table || $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$table))!==$table) return;
        $cols=$wpdb->get_col("DESC {$table}",0);
        if(!in_array('sunday_reminder_enabled',$cols,true))
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN sunday_reminder_enabled tinyint(1) NOT NULL DEFAULT 0 AFTER reminder_unit");
    }

    private function ensure_attendance_leave_table(){
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $charset=$wpdb->get_charset_collate();
        $table=$this->tables['attendance_leaves'] ?? ($wpdb->prefix.'bb_attendance_leaves');
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            employee_id bigint(20) unsigned NOT NULL,
            from_date date NOT NULL,
            to_date date NOT NULL,
            reason text NOT NULL,
            status varchar(20) DEFAULT 'pending',
            approver_employee_id bigint(20) unsigned DEFAULT NULL,
            approval_remark text DEFAULT NULL,
            requested_at datetime DEFAULT CURRENT_TIMESTAMP,
            decided_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY employee_status (employee_id,status),
            KEY date_range (from_date,to_date),
            KEY status (status)
        ) $charset;");
    }

    public function activate(){
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $charset=$wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$this->tables['workspaces']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(150) NOT NULL,
            slug varchar(180) DEFAULT '',
            description varchar(255) DEFAULT '',
            color varchar(20) DEFAULT '#4f46e5',
            status varchar(20) DEFAULT 'active',
            sort_order int DEFAULT 0,
            folder_id bigint(20) unsigned DEFAULT NULL,
            trash_previous_status varchar(20) DEFAULT NULL,
            source_template_id bigint(20) unsigned DEFAULT NULL,
            template_synced_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY sort_order (sort_order), KEY folder_id (folder_id), UNIQUE KEY name (name), UNIQUE KEY slug (slug)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->tables['folders']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(150) NOT NULL,
            description varchar(255) DEFAULT '',
            color varchar(20) DEFAULT '#4f46e5',
            sort_order int DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY sort_order (sort_order), UNIQUE KEY name (name)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->tables['folder_workspaces']} (
            folder_id bigint(20) unsigned NOT NULL,
            workspace_id bigint(20) unsigned NOT NULL,
            sort_order int DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (folder_id, workspace_id),
            KEY workspace_id (workspace_id),
            KEY sort_order (sort_order)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->tables['notespaces']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(150) NOT NULL,
            slug varchar(180) DEFAULT '',
            description varchar(255) DEFAULT '',
            color varchar(20) DEFAULT '#7c3aed',
            status varchar(20) DEFAULT 'active',
            sort_order int DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY sort_order (sort_order), UNIQUE KEY slug (slug)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->tables['folder_notespaces']} (
            folder_id bigint(20) unsigned NOT NULL,
            notespace_id bigint(20) unsigned NOT NULL,
            sort_order int DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (folder_id, notespace_id),
            KEY notespace_id (notespace_id),
            KEY sort_order (sort_order)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->tables['note_areas']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            notespace_id bigint(20) unsigned NOT NULL,
            name varchar(150) NOT NULL,
            description varchar(255) DEFAULT '',
            color varchar(20) DEFAULT '#7c3aed',
            date_applicable tinyint(1) NOT NULL DEFAULT 0,
            applicable_date date DEFAULT NULL,
            sort_order int DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY notespace_id (notespace_id), KEY sort_order (sort_order)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->tables['note_cards']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            notespace_id bigint(20) unsigned NOT NULL,
            note_area_id bigint(20) unsigned NOT NULL,
            note_type varchar(20) DEFAULT 'text',
            title varchar(255) DEFAULT '',
            text_content longtext,
            url text,
            link_title text,
            link_description text,
            thumbnail_url text,
            note_date date DEFAULT NULL,
            sort_order int DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY notespace_id (notespace_id), KEY note_area_id (note_area_id), KEY sort_order (sort_order)
        ) $charset;");

        // v4.11.82: migrate original single-Folder links into the new
        // many-to-many membership table without disturbing newer links.
        $wpdb->query("INSERT IGNORE INTO {$this->tables['folder_workspaces']} (folder_id,workspace_id,sort_order,created_at)
                      SELECT folder_id,id,sort_order,CURRENT_TIMESTAMP FROM {$this->tables['workspaces']}
                      WHERE folder_id IS NOT NULL AND folder_id>0");

        dbDelta("CREATE TABLE {$this->tables['work_areas']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(150) NOT NULL,
            description varchar(255) DEFAULT '',
            head_employee_id bigint(20) unsigned DEFAULT NULL,
            color varchar(20) DEFAULT '#4f46e5',
            status varchar(20) DEFAULT 'active',
            stages_json longtext DEFAULT NULL,
            update_remarks_required tinyint(1) NOT NULL DEFAULT 0,
            remarks_view_days_limit int unsigned NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY name (name)
        ) $charset;");

        // v4.13.73: business-level reusable Task Stage Master.
        // Work Areas still keep their own selected stages/order/color override in stages_json.
        dbDelta("CREATE TABLE {$this->tables['stage_master']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            normalized_name varchar(120) NOT NULL,
            default_color varchar(20) DEFAULT '#64748b',
            status varchar(20) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY normalized_name (normalized_name),
            KEY status_name (status,name)
        ) $charset;");

        // v4.15.31: reusable Task Labels Master.
        dbDelta("CREATE TABLE {$this->tables['labels']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(120) NOT NULL,
            normalized_name varchar(140) NOT NULL,
            status varchar(20) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY normalized_name (normalized_name),
            KEY status_name (status,name)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->tables['workspace_areas']} (
            workspace_id bigint(20) unsigned NOT NULL,
            work_area_id bigint(20) unsigned NOT NULL,
            sort_order int DEFAULT 0,
            PRIMARY KEY (workspace_id, work_area_id),
            KEY work_area_id (work_area_id)
        ) $charset;");
        // v3.3: Work Area names may repeat across different Workspaces; they are no longer global masters.
        $idx=$wpdb->get_results("SHOW INDEX FROM {$this->tables['work_areas']} WHERE Key_name='name'");
        if($idx && isset($idx[0]->Non_unique) && (int)$idx[0]->Non_unique===0){
            $wpdb->query("ALTER TABLE {$this->tables['work_areas']} DROP INDEX name");
            $wpdb->query("ALTER TABLE {$this->tables['work_areas']} ADD KEY name (name)");
        }

        dbDelta("CREATE TABLE {$this->tables['employees']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            employee_code varchar(40) DEFAULT '',
            name varchar(150) NOT NULL,
            email varchar(190) DEFAULT '',
            mobile varchar(30) DEFAULT '',
            work_area_id bigint(20) unsigned DEFAULT NULL,
            designation varchar(120) DEFAULT '',
            department varchar(150) DEFAULT '',
            address text,
            system_role varchar(20) DEFAULT 'employee',
            status varchar(20) DEFAULT 'active',
            joined_on date DEFAULT NULL,
            attendance_enabled tinyint(1) DEFAULT 0,
            attendance_enabled_from date DEFAULT NULL,
            attendance_supervisor tinyint(1) DEFAULT 0,
            attendance_supervisor_employee_ids longtext DEFAULT NULL,
            attendance_supervisor_employee_id bigint(20) unsigned DEFAULT NULL,
            attendance_view_others tinyint(1) DEFAULT 0,
            attendance_view_employee_id bigint(20) unsigned DEFAULT NULL,
            attendance_viewer_employee_ids longtext DEFAULT NULL,
            salary decimal(15,2) DEFAULT 0.00,
            use_org_work_hours tinyint(1) DEFAULT 1,
            in_time time DEFAULT NULL,
            out_time time DEFAULT NULL,
            use_org_weekly_off tinyint(1) DEFAULT 1,
            weekly_off_rule varchar(255) DEFAULT '',
            wp_user_id bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY work_area_id (work_area_id), KEY wp_user_id (wp_user_id)
        ) $charset;");

        // v4.13.85: AttendSpace stores only real attendance punches. Date areas are virtual in the frontend.
        dbDelta("CREATE TABLE {$this->tables['attendance_combos']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(120) NOT NULL,
            out_label varchar(150) NOT NULL,
            in_label varchar(150) NOT NULL,
            status varchar(20) DEFAULT 'active',
            counts_as_working_time tinyint(1) DEFAULT 0,
            sort_order int DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status_sort (status,sort_order),
            KEY name (name)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->tables['attendance_events']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            employee_id bigint(20) unsigned NOT NULL,
            attendance_date date NOT NULL,
            event_type varchar(20) NOT NULL DEFAULT 'main',
            combo_id bigint(20) unsigned DEFAULT NULL,
            direction varchar(10) NOT NULL,
            action_label varchar(150) NOT NULL,
            punched_at datetime NOT NULL,
            created_by_employee_id bigint(20) unsigned DEFAULT NULL,
            created_by_wp_user_id bigint(20) unsigned DEFAULT NULL,
            source varchar(30) DEFAULT 'web',
            latitude decimal(10,7) DEFAULT NULL,
            longitude decimal(10,7) DEFAULT NULL,
            location_accuracy decimal(10,2) DEFAULT NULL,
            location_address text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY employee_date (employee_id,attendance_date),
            KEY combo_date (combo_id,attendance_date),
            KEY punched_at (punched_at)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->tables['attendance_audit']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint(20) unsigned DEFAULT NULL,
            employee_id bigint(20) unsigned NOT NULL,
            action varchar(30) NOT NULL,
            old_punched_at datetime DEFAULT NULL,
            new_punched_at datetime DEFAULT NULL,
            reason varchar(255) DEFAULT '',
            changed_by_employee_id bigint(20) unsigned DEFAULT NULL,
            changed_by_wp_user_id bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY employee_created (employee_id,created_at),
            KEY event_id (event_id)
        ) $charset;");

        // v4.13.90: simple date-based Holiday marking. This is intentionally not a festival master.
        dbDelta("CREATE TABLE {$this->tables['attendance_holidays']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            holiday_date date NOT NULL,
            holiday_name varchar(190) DEFAULT '',
            created_by_employee_id bigint(20) unsigned DEFAULT NULL,
            created_by_wp_user_id bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY holiday_date (holiday_date)
        ) $charset;");

        // v4.13.92: Employee-requested Weekly Off with Manager/Owner approval.
        dbDelta("CREATE TABLE {$this->tables['attendance_weekly_off_claims']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            employee_id bigint(20) unsigned NOT NULL,
            claim_date date NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            reason varchar(255) DEFAULT '',
            review_note varchar(255) DEFAULT '',
            submitted_at datetime DEFAULT CURRENT_TIMESTAMP,
            reviewed_by_employee_id bigint(20) unsigned DEFAULT NULL,
            reviewed_by_wp_user_id bigint(20) unsigned DEFAULT NULL,
            reviewed_at datetime DEFAULT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY employee_date (employee_id,claim_date),
            KEY claim_status (status,claim_date),
            KEY employee_status (employee_id,status)
        ) $charset;");

        // v4.14.54: immutable payroll-minute ledger for approved overtime and Manager/Owner adjustments.
        dbDelta("CREATE TABLE {$this->tables['attendance_leaves']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            employee_id bigint(20) unsigned NOT NULL,
            from_date date NOT NULL,
            to_date date NOT NULL,
            reason text NOT NULL,
            status varchar(20) DEFAULT 'pending',
            approver_employee_id bigint(20) unsigned DEFAULT NULL,
            approval_remark text DEFAULT NULL,
            requested_at datetime DEFAULT CURRENT_TIMESTAMP,
            decided_at datetime DEFAULT NULL,
            PRIMARY KEY (id), KEY employee_status (employee_id,status), KEY date_range (from_date,to_date), KEY status (status)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->tables['attendance_payroll_adjustments']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            employee_id bigint(20) unsigned NOT NULL,
            attendance_date date NOT NULL,
            adjustment_type varchar(30) NOT NULL,
            minutes int NOT NULL DEFAULT 0,
            reason varchar(255) NOT NULL,
            created_by_employee_id bigint(20) unsigned DEFAULT NULL,
            created_by_wp_user_id bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY employee_date (employee_id,attendance_date),
            KEY attendance_date (attendance_date),
            KEY adjustment_type (adjustment_type)
        ) $charset;");

        // v4.13.90: controlled Designation Master used by the User form.
        dbDelta("CREATE TABLE {$this->tables['designations']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(150) NOT NULL,
            status varchar(20) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY name (name),
            KEY status (status)
        ) $charset;");

        // v4.13.79: flexible business Party Master. Party Type is intentionally free text
        // so businesses can use Customer, Vendor, Dealer, Consultant, Prospect, etc.
        dbDelta("CREATE TABLE {$this->tables['parties']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            party_name varchar(190) NOT NULL,
            party_type varchar(150) NOT NULL,
            party_mobile varchar(30) DEFAULT '',
            party_email varchar(190) DEFAULT '',
            party_address text,
            city varchar(120) DEFAULT '',
            state varchar(120) DEFAULT '',
            pin varchar(20) DEFAULT '',
            login_enabled tinyint(1) DEFAULT 0,
            login_id varchar(120) DEFAULT '',
            wp_user_id bigint(20) unsigned DEFAULT NULL,
            status varchar(20) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY party_name (party_name),
            KEY party_type (party_type),
            KEY status (status)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->tables['work_area_parties']} (
            work_area_id bigint(20) unsigned NOT NULL,
            party_id bigint(20) unsigned NOT NULL,
            rights varchar(100) DEFAULT 'view',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (work_area_id,party_id), KEY party_id (party_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->tables['workspace_parties']} (
            workspace_id bigint(20) unsigned NOT NULL,
            party_id bigint(20) unsigned NOT NULL,
            rights varchar(100) DEFAULT 'view',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (workspace_id,party_id), KEY party_id (party_id)
        ) $charset;");


        dbDelta("CREATE TABLE {$this->tables['departments']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            department_code varchar(80) NOT NULL,
            name varchar(150) NOT NULL,
            description varchar(255) DEFAULT '',
            head_employee_id bigint(20) unsigned DEFAULT NULL,
            login_enabled tinyint(1) DEFAULT 1,
            can_complete tinyint(1) DEFAULT 1,
            wp_user_id bigint(20) unsigned DEFAULT NULL,
            status varchar(20) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            archived_at datetime DEFAULT NULL,
            PRIMARY KEY (id), UNIQUE KEY department_code (department_code), UNIQUE KEY name (name), KEY wp_user_id (wp_user_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->tables['employee_departments']} (
            employee_id bigint(20) unsigned NOT NULL,
            department_id bigint(20) unsigned NOT NULL,
            is_primary tinyint(1) DEFAULT 0,
            PRIMARY KEY (employee_id,department_id), KEY department_id (department_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->tables['workspace_departments']} (
            workspace_id bigint(20) unsigned NOT NULL,
            department_id bigint(20) unsigned NOT NULL,
            PRIMARY KEY (workspace_id,department_id), KEY department_id (department_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->tables['entity_assignments']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            entity_type varchar(20) NOT NULL,
            entity_id bigint(20) unsigned NOT NULL,
            employee_id bigint(20) unsigned NOT NULL,
            relation_type varchar(20) NOT NULL DEFAULT 'assignee',
            rights varchar(20) NOT NULL DEFAULT 'view_edit',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            archived_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY entity_user_relation (entity_type,entity_id,employee_id,relation_type),
            KEY employee_id (employee_id), KEY entity_lookup (entity_type,entity_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->tables['task_remarks']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            task_id bigint(20) unsigned NOT NULL,
            occurrence_key varchar(32) NOT NULL DEFAULT 'one',
            employee_id bigint(20) unsigned DEFAULT NULL,
            remark text NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY task_occurrence (task_id,occurrence_key), KEY employee_id (employee_id), KEY created_at (created_at)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->tables['tasks']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            task_name varchar(220) NOT NULL,
            slug varchar(240) DEFAULT NULL,
            workspace_id bigint(20) unsigned DEFAULT NULL,
            work_area_id bigint(20) unsigned DEFAULT NULL,
            assignee_id bigint(20) unsigned DEFAULT NULL,
            priority varchar(20) DEFAULT 'Medium',
            status varchar(30) DEFAULT 'Pending',
            stage_name varchar(100) DEFAULT '',
            task_type varchar(20) DEFAULT 'One Time',
            template_key varchar(64) DEFAULT '',
            is_template tinyint(1) DEFAULT 0,
            template_id bigint(20) unsigned DEFAULT NULL,
            frequency varchar(30) DEFAULT 'None',
            interval_value int DEFAULT 1,
            interval_unit varchar(20) DEFAULT 'Days',
            start_date date DEFAULT NULL,
            end_date date DEFAULT NULL,
            due_date date DEFAULT NULL,
            due_time time DEFAULT NULL,
            occurrence_date date DEFAULT NULL,
            description text,
            labels text,
            estimated_minutes int DEFAULT 0,
            is_billable tinyint(1) DEFAULT 0,
            billable_amount decimal(15,2) DEFAULT NULL,
            billed_amount decimal(15,2) DEFAULT NULL,
            billing_updated_at datetime DEFAULT NULL,
            notify_enabled tinyint(1) DEFAULT 1,
            reminder_enabled tinyint(1) DEFAULT 0,
            reminder_value int DEFAULT 1,
            reminder_unit varchar(20) DEFAULT 'Hours',
            sunday_reminder_enabled tinyint(1) NOT NULL DEFAULT 0,
            show_subtasks tinyint(1) DEFAULT 0,
            recurrence_status varchar(20) DEFAULT 'active',
            overdue_policy varchar(30) DEFAULT 'until_next',
            recurrence_paused_from date DEFAULT NULL,
            completion_result varchar(30) DEFAULT '',
            completed_by_employee_id bigint(20) unsigned DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            pinned_at datetime DEFAULT NULL,
            is_focus tinyint(1) DEFAULT 0,
            focus_auto_date date DEFAULT NULL,
            focus_manual_override_date date DEFAULT NULL,
            sort_order int DEFAULT 0,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            archived_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY task_slug (slug),
            KEY workspace_id (workspace_id), KEY work_area_id (work_area_id), KEY assignee_id (assignee_id),
            KEY due_date (due_date), KEY status (status), KEY template_id (template_id), KEY is_template (is_template), KEY template_key (template_key)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->tables['checklists']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            task_id bigint(20) unsigned NOT NULL,
            item_text varchar(255) NOT NULL,
            sort_order int DEFAULT 0,
            is_done tinyint(1) DEFAULT 0,
            PRIMARY KEY (id), KEY task_id (task_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->tables['subtask_progress']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            task_id bigint(20) unsigned NOT NULL,
            subtask_id bigint(20) unsigned NOT NULL,
            occurrence_key varchar(32) NOT NULL DEFAULT 'one',
            is_done tinyint(1) DEFAULT 0,
            completed_by_employee_id bigint(20) unsigned DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            archived_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY subtask_occurrence (task_id,subtask_id,occurrence_key),
            KEY task_occurrence (task_id,occurrence_key)
        ) $charset;");


        dbDelta("CREATE TABLE {$this->tables['history']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            task_id bigint(20) unsigned NOT NULL,
            occurrence_date date NOT NULL,
            due_date date NOT NULL,
            due_time time DEFAULT NULL,
            assignee_id bigint(20) unsigned DEFAULT NULL,
            workspace_id bigint(20) unsigned DEFAULT NULL,
            work_area_id bigint(20) unsigned DEFAULT NULL,
            status varchar(30) DEFAULT 'Pending',
            stage_name varchar(100) DEFAULT '',
            result varchar(30) DEFAULT '',
            completed_by_employee_id bigint(20) unsigned DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            score_points int DEFAULT 0,
            note varchar(255) DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            archived_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY task_occurrence (task_id, occurrence_date),
            KEY due_date (due_date), KEY assignee_id (assignee_id), KEY workspace_id (workspace_id), KEY work_area_id (work_area_id), KEY status (status)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->tables['score_archive']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            period_month date NOT NULL,
            task_id bigint(20) unsigned NOT NULL,
            assignee_id bigint(20) unsigned DEFAULT NULL,
            workspace_id bigint(20) unsigned DEFAULT NULL,
            work_area_id bigint(20) unsigned DEFAULT NULL,
            due_count int DEFAULT 0,
            early_count int DEFAULT 0,
            on_time_count int DEFAULT 0,
            late_count int DEFAULT 0,
            missed_count int DEFAULT 0,
            skipped_count int DEFAULT 0,
            total_points int DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            archived_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY score_bucket (period_month, task_id, assignee_id, workspace_id, work_area_id),
            KEY period_month (period_month), KEY assignee_id (assignee_id), KEY workspace_id (workspace_id), KEY work_area_id (work_area_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->tables['workspace_templates']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(180) NOT NULL,
            description varchar(255) DEFAULT '',
            snapshot longtext NOT NULL,
            status varchar(20) DEFAULT 'active',
            created_by bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            archived_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY status (status), KEY updated_at (updated_at)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->tables['workspace_access']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            employee_id bigint(20) unsigned NOT NULL,
            workspace_id bigint(20) unsigned NOT NULL,
            access_role varchar(30) DEFAULT 'member',
            access_mode varchar(20) DEFAULT 'allow',
            can_add tinyint(1) DEFAULT 0,
            can_edit tinyint(1) DEFAULT 0,
            can_complete tinyint(1) DEFAULT 1,
            can_delete tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            archived_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY employee_workspace (employee_id,workspace_id),
            KEY workspace_id (workspace_id), KEY employee_id (employee_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->tables['work_area_templates']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(180) NOT NULL,
            snapshot longtext NOT NULL,
            created_by bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY name (name)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->tables['activity']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned DEFAULT NULL,
            employee_id bigint(20) unsigned DEFAULT NULL,
            workspace_id bigint(20) unsigned DEFAULT NULL,
            work_area_id bigint(20) unsigned DEFAULT NULL,
            task_id bigint(20) unsigned DEFAULT NULL,
            occurrence_date date DEFAULT NULL,
            action varchar(50) NOT NULL,
            details varchar(500) DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY workspace_id (workspace_id), KEY task_id (task_id), KEY created_at (created_at), KEY employee_id (employee_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->tables['recurrence_exceptions']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            task_id bigint(20) unsigned NOT NULL,
            occurrence_date date NOT NULL,
            exception_type varchar(30) DEFAULT 'Skipped',
            note varchar(255) DEFAULT '',
            created_by bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY task_date (task_id,occurrence_date),
            KEY occurrence_date (occurrence_date)
        ) $charset;");


        dbDelta("CREATE TABLE {$this->tables['notifications']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            employee_id bigint(20) unsigned DEFAULT NULL,
            wp_user_id bigint(20) unsigned DEFAULT NULL,
            workspace_id bigint(20) unsigned DEFAULT NULL,
            task_id bigint(20) unsigned DEFAULT NULL,
            occurrence_date date DEFAULT NULL,
            type varchar(40) NOT NULL,
            title varchar(220) NOT NULL,
            message varchar(500) DEFAULT '',
            source_key varchar(190) DEFAULT '',
            is_read tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY source_key (source_key),
            KEY employee_id (employee_id), KEY wp_user_id (wp_user_id), KEY is_read (is_read), KEY created_at (created_at)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->tables['attachments']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            task_id bigint(20) unsigned NOT NULL,
            occurrence_date date DEFAULT NULL,
            attachment_id bigint(20) unsigned NOT NULL,
            attachment_kind varchar(20) DEFAULT 'reference',
            uploaded_by bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY task_id (task_id), KEY occurrence_date (occurrence_date), KEY attachment_kind (attachment_kind)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->tables['template_versions']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            template_id bigint(20) unsigned NOT NULL,
            version_number int NOT NULL DEFAULT 1,
            snapshot longtext NOT NULL,
            note varchar(255) DEFAULT '',
            created_by bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY template_version (template_id,version_number),
            KEY template_id (template_id), KEY created_at (created_at)
        ) $charset;");

        // Composite indexes for common large-data queries.
        $this->ensure_index($this->tables['history'],'bb_hist_ws_due','workspace_id,due_date');
        $this->ensure_index($this->tables['history'],'bb_hist_emp_due','assignee_id,due_date');
        $this->ensure_index($this->tables['history'],'bb_hist_result_due','result,due_date');
        $this->ensure_index($this->tables['tasks'],'bb_task_ws_status_due','workspace_id,status,due_date');
        $this->ensure_index($this->tables['tasks'],'bb_task_assignee_status','assignee_id,status');
        $this->ensure_index($this->tables['activity'],'bb_activity_ws_created','workspace_id,created_at');
        $this->ensure_index($this->tables['notifications'],'bb_notify_user_read_created','wp_user_id,is_read,created_at');

        if(get_option('bb_notify_assignment')===false) update_option('bb_notify_assignment',1);
        if(get_option('bb_notify_due_soon')===false) update_option('bb_notify_due_soon',1);
        if(get_option('bb_notify_overdue')===false) update_option('bb_notify_overdue',1);
        if(get_option('bb_notify_email')===false) update_option('bb_notify_email',0);
        if(get_option('bb_notify_daily_summary')===false) update_option('bb_notify_daily_summary',0);
        if(get_option('bb_notify_morning_pending')===false) update_option('bb_notify_morning_pending',1);
        if(get_option('bb_purge_proof_with_history')===false) update_option('bb_purge_proof_with_history',0);
        if(get_option('bb_attendance_default_in_time')===false) update_option('bb_attendance_default_in_time','09:00');
        if(get_option('bb_attendance_default_out_time')===false) update_option('bb_attendance_default_out_time','18:00');
        if(get_option('bb_attendance_grace_minutes')===false) update_option('bb_attendance_grace_minutes',15);
        if(get_option('bb_attendance_full_day_minutes')===false) update_option('bb_attendance_full_day_minutes',480);
        if(get_option('bb_attendance_half_day_minutes')===false) update_option('bb_attendance_half_day_minutes',240);
        if(get_option('bb_attendance_standard_break_minutes')===false) update_option('bb_attendance_standard_break_minutes',45);
        if(get_option('bb_attendance_auto_break_after_minutes')===false) update_option('bb_attendance_auto_break_after_minutes',360);
        if(get_option('bb_attendance_missing_out_cutoff')===false) update_option('bb_attendance_missing_out_cutoff','23:55');
        if(get_option('bb_attendance_missing_out_cutoff_mode')===false) update_option('bb_attendance_missing_out_cutoff_mode','same_day');
        if(get_option('bb_attendance_default_weekly_off')===false) update_option('bb_attendance_default_weekly_off','Sunday');
        if(get_option('bb_attendance_salary_basis')===false) update_option('bb_attendance_salary_basis','calendar_days');
        if(get_option('bb_attendance_location_mode')===false) update_option('bb_attendance_location_mode','optional');
        if(get_option('bb_attendance_location_accuracy_limit')===false) update_option('bb_attendance_location_accuracy_limit',150);

        if(get_option('bb_history_retention_months')===false) update_option('bb_history_retention_months',12);
        $admin=get_role('administrator'); if($admin){ if(!$admin->has_cap('bb_manage_history')) $admin->add_cap('bb_manage_history'); if(!$admin->has_cap('bb_manage_access')) $admin->add_cap('bb_manage_access'); if(!$admin->has_cap('bb_export_data')) $admin->add_cap('bb_export_data'); }

        $this->migrate_legacy_if_needed();
        $this->migrate_v20_instances();
        $this->migrate_v33_workspace_owned_areas_and_ids();
        $this->migrate_v34_workspace_slugs();
        $this->migrate_task_slugs();
        // Rewrite rules must be flushed only after our custom routes have been
        // registered on `init`. During plugin upgrades `activate()` is called from
        // `plugins_loaded`, which is too early to flush the new rules reliably.
        // Mark them for a one-time flush in register_frontend_routes() instead.
        update_option('bb_flush_rewrite_rules', 1);
        if(!wp_next_scheduled('bb_daily_history_cleanup')) wp_schedule_event(time()+3600,'daily','bb_daily_history_cleanup');
        wp_clear_scheduled_hook('bb_hourly_notifications');
        wp_clear_scheduled_hook('bb_daily_task_summary');
        wp_clear_scheduled_hook('bb_morning_task_notifications');
        if(!wp_next_scheduled('bb_morning_task_notifications')){
            // Always schedule against Indian Standard Time, independent of the WordPress site timezone.
            $ist=new DateTimeZone('Asia/Kolkata');
            $now_ist=new DateTime('now',$ist);
            $next_ist=new DateTime($now_ist->format('Y-m-d').' 09:15:00',$ist);
            if($next_ist<=$now_ist) $next_ist->modify('+1 day');
            wp_schedule_event($next_ist->getTimestamp(),'daily','bb_morning_task_notifications');
        }
        // Reminder processing cadence: every 5 minutes. Migrate any older 1-minute event.
        $reminder_event=function_exists('wp_get_scheduled_event')?wp_get_scheduled_event('bb_task_reminder_tick'):null;
        if(!$reminder_event || ($reminder_event->schedule??'')!=='bb_every_five_minutes'){
            wp_clear_scheduled_hook('bb_task_reminder_tick');
            wp_schedule_event(time()+60,'bb_every_five_minutes','bb_task_reminder_tick');
        }

        add_role('bb_employee','Workboard User',['read'=>true]);
        add_role('bb_party','Workboard Party',['read'=>true]);
        $this->migrate_v49_departments_to_users();
        $this->migrate_v41249_notespace_sharing();
        remove_role('bb_department');
        $this->ensure_frontend_pages();
        // v3.8 priority terminology: Critical -> Urgent, Normal -> Medium.
        $wpdb->query("UPDATE {$this->tables['tasks']} SET priority='Urgent' WHERE priority='Critical'");
        $wpdb->query("UPDATE {$this->tables['tasks']} SET priority='Medium' WHERE priority='Normal' OR priority='' OR priority IS NULL");
        // v4.13.69: preserve legacy In Progress tasks inside the new Work Area stage model.
        $wpdb->query("UPDATE {$this->tables['tasks']} SET stage_name='In Progress' WHERE status='In Progress' AND (stage_name='' OR stage_name IS NULL)");
        $wpdb->query("UPDATE {$this->tables['history']} SET stage_name='In Progress' WHERE status='In Progress' AND (stage_name='' OR stage_name IS NULL)");
        // v4.13.73: seed the reusable business-level Stage Master from all existing Work Areas.
        $this->sync_stage_master_from_work_areas();
        $this->ensure_callspace_docspace_tables();
        // Plugin activation/update must not interrupt valid sessions.
        // The recurring three-day check is performed on plugins_loaded.
        update_option('bb_db_version',self::DB_VERSION);
        update_option('bb_plugin_version',self::VERSION);
    }

    private function migrate_v41249_notespace_sharing(){
        if(get_option('bb_v41249_notespace_sharing_migrated')) return;
        global $wpdb;
        $employees=$wpdb->get_results("SELECT id,system_role FROM {$this->tables['employees']} WHERE status='active' ORDER BY id ASC");
        $notespaces=$wpdb->get_col("SELECT id FROM {$this->tables['notespaces']} WHERE status='active' ORDER BY id ASC");
        foreach($notespaces as $notespace_id){
            $notespace_id=(int)$notespace_id;
            $exists=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['entity_assignments']} WHERE entity_type='notespace' AND entity_id=%d",$notespace_id));
            if($exists || !$employees) continue;
            $owner_id=0;
            foreach($employees as $employee){
                if(in_array(strtolower((string)($employee->system_role??'employee')),['owner','manager'],true)){ $owner_id=(int)$employee->id; break; }
            }
            if(!$owner_id) $owner_id=(int)$employees[0]->id;
            foreach($employees as $employee){
                $eid=(int)$employee->id;
                $rel=$eid===$owner_id?'owner':'assignee';
                $wpdb->insert($this->tables['entity_assignments'],[
                    'entity_type'=>'notespace','entity_id'=>$notespace_id,'employee_id'=>$eid,
                    'relation_type'=>$rel,'rights'=>$rel==='owner'?'view_edit':'view','updated_at'=>current_time('mysql')
                ]);
            }
        }
        update_option('bb_v41249_notespace_sharing_migrated',1);
    }

    private function migrate_v49_departments_to_users(){
        if(get_option('bb_v49_departments_migrated')) return;
        global $wpdb;
        $departments=$wpdb->get_results("SELECT * FROM {$this->tables['departments']} ORDER BY id");
        foreach($departments as $d){
            if(empty($d->wp_user_id)) continue;
            $eid=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['employees']} WHERE wp_user_id=%d LIMIT 1",$d->wp_user_id));
            if(!$eid){
                $u=get_user_by('id',(int)$d->wp_user_id);
                $wpdb->insert($this->tables['employees'],[
                    'employee_code'=>$this->next_auto_code($this->tables['employees'],'employee_code','USR'),
                    'name'=>$d->name ?: ($u?$u->display_name:'User'),
                    'email'=>$u?$u->user_email:'',
                    'wp_user_id'=>(int)$d->wp_user_id,
                    'status'=>'active',
                    'created_at'=>current_time('mysql')
                ]);
                $eid=(int)$wpdb->insert_id;
            }
            if($eid){
                $u=get_user_by('id',(int)$d->wp_user_id);
                if($u){ $u->add_role('bb_employee'); $u->remove_role('bb_department'); }
                $wids=$wpdb->get_col($wpdb->prepare("SELECT workspace_id FROM {$this->tables['workspace_departments']} WHERE department_id=%d",$d->id));
                foreach($wids as $wid){
                    $exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['entity_assignments']} WHERE entity_type='workspace' AND entity_id=%d AND employee_id=%d LIMIT 1",$wid,$eid));
                    if(!$exists) $wpdb->insert($this->tables['entity_assignments'],[
                        'entity_type'=>'workspace','entity_id'=>(int)$wid,'employee_id'=>$eid,'relation_type'=>'assignee','rights'=>'view','updated_at'=>current_time('mysql')
                    ]);
                }
            }
        }
        update_option('bb_v49_departments_migrated',1);
    }

    private function migrate_v33_workspace_owned_areas_and_ids(){
        global $wpdb;
        // Backfill friendly IDs for older rows.
        $users=$wpdb->get_results("SELECT id FROM {$this->tables['employees']} WHERE employee_code IS NULL OR employee_code=''");
        foreach($users as $u) $wpdb->update($this->tables['employees'],['employee_code'=>$this->next_auto_code($this->tables['employees'],'employee_code','USR')],['id'=>$u->id]);
        $deps=$wpdb->get_results("SELECT id FROM {$this->tables['departments']} WHERE department_code IS NULL OR department_code=''");
        foreach($deps as $d) $wpdb->update($this->tables['departments'],['department_code'=>$this->next_auto_code($this->tables['departments'],'department_code','DEPT')],['id'=>$d->id]);

        // Split any legacy Work Area that was shared by more than one Workspace.
        $shared=$wpdb->get_results("SELECT work_area_id,COUNT(*) cnt FROM {$this->tables['workspace_areas']} GROUP BY work_area_id HAVING COUNT(*)>1");
        foreach($shared as $sh){
            $area=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['work_areas']} WHERE id=%d",$sh->work_area_id)); if(!$area) continue;
            $links=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tables['workspace_areas']} WHERE work_area_id=%d ORDER BY workspace_id",$sh->work_area_id));
            array_shift($links); // first Workspace keeps original area
            foreach($links as $link){
                $wpdb->insert($this->tables['work_areas'],['name'=>$area->name,'description'=>$area->description,'head_employee_id'=>$area->head_employee_id?:null,'color'=>$area->color,'status'=>$area->status,'stages_json'=>$area->stages_json?:wp_json_encode($this->default_work_area_stages()),'created_at'=>$area->created_at?:current_time('mysql')]);
                $new_id=(int)$wpdb->insert_id; if(!$new_id) continue;
                $wpdb->delete($this->tables['workspace_areas'],['workspace_id'=>$link->workspace_id,'work_area_id'=>$sh->work_area_id]);
                $wpdb->insert($this->tables['workspace_areas'],['workspace_id'=>$link->workspace_id,'work_area_id'=>$new_id,'sort_order'=>$link->sort_order]);
                foreach(['tasks','history','score_archive','activity'] as $tk){
                    if(isset($this->tables[$tk])) $wpdb->query($wpdb->prepare("UPDATE {$this->tables[$tk]} SET work_area_id=%d WHERE workspace_id=%d AND work_area_id=%d",$new_id,$link->workspace_id,$sh->work_area_id));
                }
            }
        }
    }

    private function unique_workspace_slug($name,$exclude_id=0){
        global $wpdb;
        $base=sanitize_title($name);
        if(!$base) $base='workspace';
        $slug=$base; $i=2;
        while($wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['workspaces']} WHERE slug=%s AND id<>%d LIMIT 1",$slug,$exclude_id))){
            $slug=$base.'-'.$i; $i++;
        }
        return $slug;
    }
    private function migrate_v34_workspace_slugs(){
        global $wpdb;
        $rows=$wpdb->get_results("SELECT id,name,slug FROM {$this->tables['workspaces']} ORDER BY id");
        foreach($rows as $r){
            if(!empty($r->slug)) continue;
            $slug=$this->unique_workspace_slug($r->name,(int)$r->id);
            $wpdb->update($this->tables['workspaces'],['slug'=>$slug],['id'=>(int)$r->id]);
        }
    }
    private function task_name_length($value){
        $value=(string)$value;
        return function_exists('mb_strlen') ? mb_strlen($value,'UTF-8') : strlen($value);
    }

    private function unique_task_slug($name,$exclude_id=0){
        global $wpdb;
        $base=sanitize_title($name);
        if(!$base) $base='task';
        $slug=$base; $i=2;
        while($wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['tasks']} WHERE slug=%s AND id<>%d LIMIT 1",$slug,$exclude_id))){
            $slug=$base.'-'.$i; $i++;
        }
        return $slug;
    }
    private function migrate_task_slugs(){
        global $wpdb;
        $cols=$wpdb->get_col("DESC {$this->tables['tasks']}",0);
        if(!in_array('slug',$cols,true)) return;
        $rows=$wpdb->get_results("SELECT id,task_name,slug FROM {$this->tables['tasks']} WHERE slug IS NULL OR slug='' ORDER BY id");
        foreach($rows as $r){
            if(!empty($r->slug)) continue;
            $wpdb->update($this->tables['tasks'],['slug'=>$this->unique_task_slug($r->task_name,(int)$r->id)],['id'=>(int)$r->id]);
        }
    }
    public function register_frontend_routes(){
        // Self-heal the two frontend pages if an update/duplicate plugin install left
        // the stored page IDs missing, trashed or unpublished.
        $front=absint(get_option('bb_frontend_page_id'));
        $login=absint(get_option('bb_login_page_id'));
        $workspaces_page=absint(get_option('bb_workspaces_page_id'));
        $pending_tasks_page=absint(get_option('bb_pending_tasks_page_id'));
        $labels_page=absint(get_option('bb_labels_page_id'));
        $front_post=$front ? get_post($front) : null;
        $login_post=$login ? get_post($login) : null;
        $workspaces_post=$workspaces_page ? get_post($workspaces_page) : null;
        $pending_tasks_post=$pending_tasks_page ? get_post($pending_tasks_page) : null;
        $labels_post=$labels_page ? get_post($labels_page) : null;
        if(!$front_post || $front_post->post_status!=='publish' || !$login_post || $login_post->post_status!=='publish' || !$workspaces_post || $workspaces_post->post_status!=='publish' || !$pending_tasks_post || $pending_tasks_post->post_status!=='publish' || !$labels_post || $labels_post->post_status!=='publish'){
            $this->ensure_frontend_pages();
        }
        // Dedicated slugs for every primary frontend view. These routes all
        // render the same Workboard app shell, while JavaScript opens the requested view.
        $view_routes=[
            'dashboard'=>'dashboard',
            'folder'=>'folders',
            'workspace-templates'=>'templates',
            'work-area-templates'=>'work-area-templates',
            'focus'=>'focus',
            'pending-tasks'=>'pending-tasks',
            'daily-scoreboard'=>'daily-scoreboard',
            'pending-billing'=>'pending-billing',
            'billed-master'=>'billed-master',
            'users'=>'employees',
            'parties'=>'parties',
            'attendspace'=>'attendspace',
            'attendance-register'=>'attendance-register',
            'monthly-attendance'=>'monthly-sheet',
            'leave'=>'leave',
            'pending-leave-approval'=>'pending-leave',
            'attendance-settings'=>'attendance-settings',
            'departments'=>'departments',
            'designations'=>'designations',
            'labels'=>'labels',
            'holidays'=>'holidays',
            'settings'=>'settings',
            'activity-log'=>'activity',
            'daily-activity'=>'timesheet',
            'notespaces'=>'notespaces',
            'mathspaces'=>'mathspaces',
            'callspace'=>'callspace',
            'docspace'=>'docspace',
        ];
        foreach($view_routes as $slug=>$view){
            add_rewrite_rule('^'.preg_quote($slug,'/').'/?$','index.php?bb_view='.$view,'top');
        }
        add_rewrite_rule('^workspaces/([^/]+)/?$','index.php?bb_workspace_slug=$matches[1]','top');
        add_rewrite_rule('^business-board/workspace/([^/]+)/?$','index.php?bb_workspace_slug=$matches[1]','top');
        add_rewrite_rule('^business-board/task/([^/]+)/?$','index.php?bb_task_slug=$matches[1]','top');

        // One-time rewrite refresh after install/update. At this point the custom
        // WorkBoard routes are already present in WP's rewrite rules, so flushing
        // cannot accidentally remove /business-board/workspace/... links.
        $rewrite_version = (string)get_option('bb_rewrite_version', '');
        if(get_option('bb_flush_rewrite_rules') || $rewrite_version !== self::VERSION){
            flush_rewrite_rules(false);
            update_option('bb_rewrite_version', self::VERSION);
            delete_option('bb_flush_rewrite_rules');
        }
    }
    public function fallback_frontend_path_router($wp){
        if(is_admin() || (function_exists('wp_doing_ajax') && wp_doing_ajax())) return;
        $request_path=(string)parse_url($_SERVER['REQUEST_URI'] ?? '',PHP_URL_PATH);
        $home_path=(string)parse_url(home_url('/'),PHP_URL_PATH);
        $request_path='/'.ltrim($request_path,'/');
        $home_path='/'.trim($home_path,'/');
        if($home_path==='/' || $home_path===''){
            $local=trim($request_path,'/');
        }elseif($request_path===$home_path || $request_path===$home_path.'/'){
            $local='';
        }elseif(strpos($request_path,$home_path.'/')===0){
            $local=trim(substr($request_path,strlen($home_path)),'/');
        }else{
            return;
        }
        $routes=[
            'dashboard'=>'dashboard',
            'workspaces'=>'workspaces',
            'folder'=>'folders',
            'workspace-templates'=>'templates',
            'work-area-templates'=>'work-area-templates',
            'focus'=>'focus',
            'pending-tasks'=>'pending-tasks',
            'daily-scoreboard'=>'daily-scoreboard',
            'pending-billing'=>'pending-billing',
            'billed-master'=>'billed-master',
            'users'=>'employees',
            'parties'=>'parties',
            'attendspace'=>'attendspace',
            'attendance-register'=>'attendance-register',
            'monthly-attendance'=>'monthly-sheet',
            'leave'=>'leave',
            'pending-leave-approval'=>'pending-leave',
            'attendance-settings'=>'attendance-settings',
            'departments'=>'departments',
            'designations'=>'designations',
            'labels'=>'labels',
            'holidays'=>'holidays',
            'settings'=>'settings',
            'activity-log'=>'activity',
            'daily-activity'=>'timesheet',
            'notespaces'=>'notespaces',
            'mathspaces'=>'mathspaces',
            'callspace'=>'callspace',
            'docspace'=>'docspace',
        ];
        $view='';
        if(isset($routes[$local])){
            $view=$routes[$local];
        }elseif($local===''){
            // WorkBoard owns the site root. This avoids another frontend plugin/theme
            // rendering its landing content (for example a different app's managed-by
            // page) when users open the bare WorkBoard site URL.
            $view='dashboard';
        }
        if($view==='') return;
        $wp->query_vars['bb_view']=$view;
        unset($wp->query_vars['error']);
        $wp->matched_rule='bb_workboard_fallback';
        $wp->matched_query='bb_view='.$view;
    }

    public function frontend_query_vars($vars){ $vars[]='bb_workspace_slug'; $vars[]='bb_task_slug'; $vars[]='bb_view'; return $vars; }
    public function frontend_no_cache(){
        if(!$this->is_business_board_frontend()) return;
        if(!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        if(!defined('DONOTCACHEOBJECT')) define('DONOTCACHEOBJECT', true);
        if(!defined('DONOTCACHEDB')) define('DONOTCACHEDB', true);
        nocache_headers();
        if(!headers_sent()){
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
        }
    }
    private function is_business_board_frontend(){
        $front=absint(get_option('bb_frontend_page_id'));
        $login=absint(get_option('bb_login_page_id'));
        $workspaces=absint(get_option('bb_workspaces_page_id'));
        $pending_tasks=absint(get_option('bb_pending_tasks_page_id'));
        $labels=absint(get_option('bb_labels_page_id'));
        return is_page($front)||is_page($login)||is_page($workspaces)||is_page($pending_tasks)||is_page($labels)||get_query_var('bb_workspace_slug')||get_query_var('bb_task_slug')||get_query_var('bb_view');
    }
    public function frontend_blank_template($template){
        $front=absint(get_option('bb_frontend_page_id'));
        $login=absint(get_option('bb_login_page_id'));
        $workspaces=absint(get_option('bb_workspaces_page_id'));
        $pending_tasks=absint(get_option('bb_pending_tasks_page_id'));
        $labels=absint(get_option('bb_labels_page_id'));
        $is_bb_page=is_page($front)||is_page($login)||is_page($workspaces)||is_page($pending_tasks)||is_page($labels)||get_query_var('bb_workspace_slug')||get_query_var('bb_task_slug')||get_query_var('bb_view');
        if(!$is_bb_page) return $template;
        if(get_query_var('bb_workspace_slug')||get_query_var('bb_task_slug')||get_query_var('bb_view')){ global $wp_query; if($wp_query){ $wp_query->is_404=false; } status_header(200); }
        // Enqueue Workboard assets BEFORE blank-app.php runs wp_head().
        // Previously the shortcode enqueued them from inside <body>, which caused
        // the raw/un-styled Workboard HTML to flash briefly on the frontend.
        $this->enqueue_assets();
        $blank=plugin_dir_path(__FILE__).'templates/blank-app.php';
        return file_exists($blank)?$blank:$template;
    }
    private function workspace_url($workspace){
        $slug=is_object($workspace)?($workspace->slug??''):(is_array($workspace)?($workspace['slug']??''):'');
        return home_url('/workspaces/'.rawurlencode($slug).'/');
    }
    private function task_url($task){
        $slug=is_object($task)?($task->slug??''):(is_array($task)?($task['slug']??''):'');
        return $slug?home_url('/business-board/task/'.rawurlencode($slug).'/'):$this->frontend_url();
    }
    private function ensure_frontend_pages(){
        $page=get_page_by_path('business-board',OBJECT,'page');
        if(!$page){
            $id=wp_insert_post(['post_title'=>'Workboard','post_name'=>'business-board','post_content'=>'[business_board]','post_status'=>'publish','post_type'=>'page']);
            if(!is_wp_error($id)) update_option('bb_frontend_page_id',$id);
        } else {
            if($page->post_status!=='publish') wp_update_post(['ID'=>$page->ID,'post_status'=>'publish']);
            if(trim((string)$page->post_content)==='') wp_update_post(['ID'=>$page->ID,'post_content'=>'[business_board]']);
            update_option('bb_frontend_page_id',$page->ID);
        }
        // v4.14.42: create a real Workspaces page on every site. Previously
        // /workspaces/ depended only on a custom rewrite rule, which can be stale or
        // bypassed on some multisite/server configurations. A physical published page
        // gives the WhatsApp Open Workspaces button a canonical WordPress permalink.
        $workspaces=get_page_by_path('workspaces',OBJECT,'page');
        if(!$workspaces){
            $id=wp_insert_post(['post_title'=>'Workspaces','post_name'=>'workspaces','post_content'=>'[business_board]','post_status'=>'publish','post_type'=>'page']);
            if(!is_wp_error($id)) update_option('bb_workspaces_page_id',$id);
        } else {
            if($workspaces->post_status!=='publish') wp_update_post(['ID'=>$workspaces->ID,'post_status'=>'publish']);
            if(trim((string)$workspaces->post_content)==='') wp_update_post(['ID'=>$workspaces->ID,'post_content'=>'[business_board]']);
            update_option('bb_workspaces_page_id',$workspaces->ID);
        }

        // v4.15.35: physical frontend pages for Pending Tasks and Labels.
        // These make the views directly addressable and avoid inheriting a stale
        // Workspace or Task URL when users navigate to these views.
        $pending_tasks=get_page_by_path('pending-tasks',OBJECT,'page');
        if(!$pending_tasks){
            $id=wp_insert_post(['post_title'=>'Pending Tasks','post_name'=>'pending-tasks','post_content'=>'[business_board]','post_status'=>'publish','post_type'=>'page']);
            if(!is_wp_error($id)) update_option('bb_pending_tasks_page_id',$id);
        } else {
            if($pending_tasks->post_status!=='publish') wp_update_post(['ID'=>$pending_tasks->ID,'post_status'=>'publish']);
            if(trim((string)$pending_tasks->post_content)==='') wp_update_post(['ID'=>$pending_tasks->ID,'post_content'=>'[business_board]']);
            update_option('bb_pending_tasks_page_id',$pending_tasks->ID);
        }

        $labels=get_page_by_path('labels',OBJECT,'page');
        if(!$labels){
            $id=wp_insert_post(['post_title'=>'Labels','post_name'=>'labels','post_content'=>'[business_board]','post_status'=>'publish','post_type'=>'page']);
            if(!is_wp_error($id)) update_option('bb_labels_page_id',$id);
        } else {
            if($labels->post_status!=='publish') wp_update_post(['ID'=>$labels->ID,'post_status'=>'publish']);
            if(trim((string)$labels->post_content)==='') wp_update_post(['ID'=>$labels->ID,'post_content'=>'[business_board]']);
            update_option('bb_labels_page_id',$labels->ID);
        }

        $login=get_page_by_path('business-board-login',OBJECT,'page');
        if(!$login){
            $id=wp_insert_post(['post_title'=>'Workboard Login','post_name'=>'business-board-login','post_content'=>'[business_board_login]','post_status'=>'publish','post_type'=>'page']);
            if(!is_wp_error($id)) update_option('bb_login_page_id',$id);
        } else {
            if($login->post_status!=='publish') wp_update_post(['ID'=>$login->ID,'post_status'=>'publish']);
            if(trim((string)$login->post_content)==='') wp_update_post(['ID'=>$login->ID,'post_content'=>'[business_board_login]']);
            update_option('bb_login_page_id',$login->ID);
        }
    }
    private function frontend_url(){
        // Canonical Workboard landing route for the CURRENT site. On a path-based
        // multisite, home_url() already includes the subsite path (for example
        // /rajmahal), so callers must pass only the route local to that site.
        return home_url('/workspaces/');
    }
    private function current_frontend_route_url(){
        // Never rebuild a current route by passing REQUEST_URI into home_url().
        // REQUEST_URI already contains the multisite subsite path, while home_url()
        // adds that path again. That produced URLs such as
        // /rajmahal/rajmahal/workspaces/ after login. Reconstruct only from the
        // Workboard query vars so the subsite path is added exactly once.
        $workspace_slug=sanitize_title((string)get_query_var('bb_workspace_slug'));
        if($workspace_slug) return home_url('/workspaces/'.rawurlencode($workspace_slug).'/');
        $task_slug=sanitize_title((string)get_query_var('bb_task_slug'));
        if($task_slug) return home_url('/business-board/task/'.rawurlencode($task_slug).'/');
        $view=sanitize_key((string)get_query_var('bb_view'));
        $routes=[
            'dashboard'=>'dashboard',
            'workspaces'=>'workspaces',
            'templates'=>'workspace-templates',
            'work-area-templates'=>'work-area-templates',
            'focus'=>'focus',
            'pending-tasks'=>'pending-tasks',
            'daily-scoreboard'=>'daily-scoreboard',
            'pending-billing'=>'pending-billing',
            'billed-master'=>'billed-master',
            'employees'=>'users',
            'parties'=>'parties',
            'attendspace'=>'attendspace',
            'attendance-register'=>'attendance-register',
            'monthly-sheet'=>'monthly-attendance',
            'leave'=>'leave',
            'pending-leave'=>'pending-leave-approval',
            'attendance-settings'=>'attendance-settings',
            'departments'=>'departments',
            'designations'=>'designations',
            'labels'=>'labels',
            'holidays'=>'holidays',
            'settings'=>'settings',
            'activity'=>'activity-log',
            'timesheet'=>'daily-activity',
            'folders'=>'folder',
            'notespaces'=>'notespaces',
            'mathspaces'=>'mathspaces',
            'callspace'=>'callspace',
            'docspace'=>'docspace',
        ];
        if($view && isset($routes[$view])) return home_url('/'.$routes[$view].'/');
        return $this->frontend_url();
    }

    private function ensure_index($table,$name,$columns){
        global $wpdb; $name=preg_replace('/[^A-Za-z0-9_]/','',$name); if(!$name) return;
        $exists=$wpdb->get_var($wpdb->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name=%s",$name));
        if(!$exists) $wpdb->query("ALTER TABLE `{$table}` ADD INDEX `{$name}` ({$columns})");
    }

    private function migrate_legacy_if_needed(){
        global $wpdb;
        $old_dep=$wpdb->prefix.'bb_departments';
        $exists=$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$old_dep));
        if($exists!==$old_dep) return;
        $new_count=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->tables['work_areas']}");
        $old_count=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$old_dep}");
        if($new_count || !$old_count) return;

        $rows=$wpdb->get_results("SELECT * FROM {$old_dep} ORDER BY id");
        foreach($rows as $r){
            $wpdb->insert($this->tables['work_areas'],[
                'name'=>$r->name,'description'=>$r->description,'head_employee_id'=>$r->manager_employee_id,
                'color'=>$r->color ?: '#4f46e5','status'=>$r->status ?: 'active'
            ]);
        }
        // Only for an upgrade containing legacy data; fresh installs remain completely empty.
        $wpdb->insert($this->tables['workspaces'],['name'=>'Main Operations','description'=>'Migrated from the earlier single-board setup','status'=>'active','sort_order'=>$this->next_workspace_sort_order()]);
        $ws=$wpdb->insert_id;
        $areas=$wpdb->get_col("SELECT id FROM {$this->tables['work_areas']} ORDER BY id");
        foreach($areas as $i=>$aid) $wpdb->insert($this->tables['workspace_areas'],['workspace_id'=>$ws,'work_area_id'=>$aid,'sort_order'=>$i]);

        $emp_cols=$wpdb->get_results("SHOW COLUMNS FROM {$this->tables['employees']}",ARRAY_A);
        $emp_names=array_column($emp_cols,'Field');
        if(in_array('department_id',$emp_names,true) && !in_array('work_area_id',$emp_names,true)){
            $wpdb->query("ALTER TABLE {$this->tables['employees']} ADD work_area_id bigint(20) unsigned DEFAULT NULL");
            $wpdb->query("UPDATE {$this->tables['employees']} SET work_area_id=department_id WHERE work_area_id IS NULL");
        }

        $emp_cols=$wpdb->get_results("SHOW COLUMNS FROM {$this->tables['employees']}",ARRAY_A);
        $emp_col_names=array_column($emp_cols,'Field');
        if(!in_array('system_role',$emp_col_names,true)){
            $wpdb->query("ALTER TABLE {$this->tables['employees']} ADD system_role varchar(20) DEFAULT 'employee' AFTER designation");
        }
        $wpdb->query("UPDATE {$this->tables['employees']} SET system_role='employee' WHERE system_role IS NULL OR system_role='' OR system_role NOT IN ('owner','manager','employee')");
    }

    private function migrate_v20_instances(){
        global $wpdb;
        // v2.0 generated child task rows up to 30 days ahead. v2.1 converts relevant rows to compact history and removes all generated children.
        $children=$wpdb->get_results("SELECT * FROM {$this->tables['tasks']} WHERE is_template=0 AND template_id IS NOT NULL");
        if(!$children) return;
        foreach($children as $c){
            $tpl=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d",$c->template_id));
            if($tpl && $c->occurrence_date){
                $due_ts=strtotime($c->due_date.' '.($c->due_time?:'23:59:59'));
                $relevant=($c->status==='Completed') || ($due_ts && $due_ts<current_time('timestamp'));
                if($relevant){
                    $result=$c->status==='Completed' ? ($c->completion_result ?: $this->completion_result_for($c->due_date,$c->due_time,$c->completed_at)) : 'Missed';
                    $points=$result==='Late'?60:(in_array($result,['Early','On Time'],true)?100:0);
                    $exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['history']} WHERE task_id=%d AND occurrence_date=%s",$c->template_id,$c->occurrence_date));
                    if(!$exists) $wpdb->insert($this->tables['history'],[
                        'task_id'=>$c->template_id,'occurrence_date'=>$c->occurrence_date,'due_date'=>$c->due_date,'due_time'=>$c->due_time,
                        'assignee_id'=>$c->assignee_id,'workspace_id'=>$c->workspace_id,'work_area_id'=>$c->work_area_id,
                        'status'=>$c->status==='Completed'?'Completed':'Overdue','result'=>$result,'completed_at'=>$c->completed_at,'score_points'=>$points,'updated_at'=>current_time('mysql')
                    ]);
                }
            }
            $wpdb->delete($this->tables['checklists'],['task_id'=>$c->id]);
            $wpdb->delete($this->tables['tasks'],['id'=>$c->id]);
        }
    }

    public function admin_menu(){ add_menu_page('Workboard','Workboard','read','business-board',[$this,'render_admin'],'dashicons-screenoptions',3); add_options_page('Workboard Settings','Workboard','manage_options','workboard-settings',[$this,'render_backend_settings']); }

    public function render_backend_settings(){
        if(!current_user_can('manage_options')) wp_die('You do not have permission to manage Workboard settings.');
        $notice='';
        if(isset($_GET['bb_restore']) && sanitize_key(wp_unslash($_GET['bb_restore']))==='success'){
            $rows=absint($_GET['rows']??0); $unmapped=absint($_GET['unmapped']??0);
            $notice='Workboard backup restored successfully'.($rows?' ('.number_format_i18n($rows).' records).':' .');
            if($unmapped) $notice.=' '.$unmapped.' imported user'.($unmapped===1?'':'s').' could not be linked to an existing WordPress login by email; edit those Users to configure login access.';
        }
        if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['bb_backend_settings_nonce'])){
            check_admin_referer('bb_backend_settings_save','bb_backend_settings_nonce');
            $section=sanitize_key($_POST['bb_section']??'');
            if($section==='notifications'){
                update_option('bb_notify_assignment',!empty($_POST['assignment'])?1:0);
                // v4.13.15: the three legacy morning WhatsApp messages are retired.
                update_option('bb_notify_due_soon',0);
                update_option('bb_notify_overdue',0);
                update_option('bb_notify_daily_summary',0);
                update_option('bb_notify_morning_pending',!empty($_POST['morning_pending'])?1:0);
                update_option('bb_notify_email',!empty($_POST['email'])?1:0);
                update_option('bb_meta_whatsapp_enabled',!empty($_POST['whatsapp'])?1:0);
                update_option('bb_meta_whatsapp_phone_number_id',preg_replace('/\D+/','',(string)($_POST['meta_phone_number_id']??'')));
                $version=trim(sanitize_text_field($_POST['meta_api_version']??'v25.0')); if(!preg_match('/^v\d+\.\d+$/',$version)) $version='v25.0'; update_option('bb_meta_whatsapp_api_version',$version);
                update_option('bb_meta_whatsapp_country_code',preg_replace('/\D+/','',(string)($_POST['meta_country_code']??'91')) ?: '91');
                update_option('bb_meta_whatsapp_language','en');
                update_option('bb_meta_whatsapp_test_mobile',preg_replace('/[^0-9+]/','',(string)($_POST['meta_test_mobile']??'')));
                $defs=['assignment'=>'workboard_task_assigned','update'=>'workboard_task_updated','morning'=>'workboard_pending_morningreminder','urgent'=>'workboard_urgent_reminder','due_reminder'=>'workboard_due_reminder'];
                foreach($defs as $k=>$d){ update_option('bb_meta_tpl_'.$k,sanitize_key($_POST['meta_tpl_'.$k]??$d)); update_option('bb_meta_tpl_'.$k.'_enabled',!empty($_POST['meta_tpl_'.$k.'_enabled'])?1:0); }
                update_option('bb_attendance_whatsapp_template',sanitize_key($_POST['meta_tpl_attendance_summary']??'workboard_daily_attendance_summary') ?: 'workboard_daily_attendance_summary');
                update_option('bb_attendance_whatsapp_active',!empty($_POST['meta_tpl_attendance_summary_enabled'])?1:0);
                $notice='Notification and WhatsApp settings saved.';
            }elseif($section==='telegram'){
                update_option('bb_telegram_enabled',!empty($_POST['telegram_enabled'])?1:0);
                $tg_token=trim((string)wp_unslash($_POST['telegram_bot_token']??''));
                if($tg_token!=='') update_option('bb_telegram_bot_token',sanitize_text_field($tg_token),false);
                $chat=preg_replace('/[^0-9\-]/','',(string)($_POST['telegram_group_chat_id']??''));
                update_option('bb_telegram_group_chat_id',$chat);
                $secret=(string)get_option('bb_telegram_webhook_secret','');
                if(!$secret){ $secret=wp_generate_password(36,false,false); update_option('bb_telegram_webhook_secret',$secret,false); }
                $notice='Telegram settings saved.';
                if(get_option('bb_telegram_enabled',0) && get_option('bb_telegram_bot_token','')){
                    $hook=$this->telegram_set_webhook();
                    $notice=!empty($hook['ok'])?'Telegram Task Inbox connected.':'Telegram settings saved, but webhook connection failed: '.($hook['message']??'Unknown error');
                    if(!empty($_POST['test_telegram'])){
                        $test=$this->telegram_send_test();
                        $notice.=!empty($test['ok'])?' Test message sent.':' Test failed: '.($test['message']??'Unknown error');
                    }
                }
            }elseif($section==='ai'){
                update_option('bb_ai_enabled',!empty($_POST['enabled'])?1:0); $provider=sanitize_key($_POST['provider']??'openai'); if(!in_array($provider,['openai','gemini'],true))$provider='openai'; update_option('bb_ai_provider',$provider);
                $model=sanitize_text_field($_POST['model']??''); if(!$model)$model=$provider==='gemini'?'gemini-3.7-flash':'gpt-5.6'; update_option('bb_ai_model',$model); update_option('bb_ai_max_file_mb',max(1,min(25,absint($_POST['max_file_mb']??10))));
                $key=trim((string)wp_unslash($_POST['api_key']??'')); if($key!=='')update_option('bb_ai_api_key',$key,false); $notice='AI settings saved.';
            }elseif($section==='branding'){
                if(!empty($_POST['remove_logo'])){ $this->delete_brand_logo_id(); $notice='Workboard logo removed from the network.'; }
                elseif(!empty($_FILES['logo_file']['name'])){ $upload=$this->upload_brand_logo('logo_file'); if(is_wp_error($upload)) $notice='Logo upload failed: '.$upload->get_error_message(); else $notice='Workboard logo updated for all sites.'; }
            }
        }
        $g=function($k,$d=''){ return get_option($k,$d); };
        ?>
        <div class="wrap"><h1>Workboard Settings</h1><?php if($notice): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
        <style>.bb-admin-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px;margin:18px 0;max-width:1000px}.bb-admin-grid{display:grid;grid-template-columns:repeat(2,minmax(260px,1fr));gap:14px 24px}.bb-admin-card label{display:block;font-weight:600}.bb-admin-card input[type=text],.bb-admin-card input[type=password],.bb-admin-card input[type=number],.bb-admin-card select{width:100%;margin-top:6px}.bb-admin-check{display:flex!important;gap:8px;align-items:center;font-weight:500!important;margin:10px 0}.bb-admin-check input{margin:0}.bb-admin-template{border-top:1px solid #eee;padding-top:12px;margin-top:12px}.bb-admin-template-head{display:flex;align-items:center;justify-content:space-between;gap:12px}.bb-admin-template-head .bb-admin-check{margin:0;flex:1}.bb-morning-test-status{margin-left:8px;font-weight:500}.bb-admin-logo img{max-width:90px;max-height:90px;object-fit:contain;border:1px solid #ddd;border-radius:8px;padding:6px;background:#fff}@media(max-width:760px){.bb-admin-grid{grid-template-columns:1fr}}</style>
        <div class="bb-admin-card"><h2>Branding</h2><p>Main-site Workboard logo is shared automatically across every site in this multisite network.</p><form method="post" enctype="multipart/form-data"><?php wp_nonce_field('bb_backend_settings_save','bb_backend_settings_nonce'); ?><input type="hidden" name="bb_section" value="branding"><div class="bb-admin-logo"><?php if($this->brand_logo_url()): ?><img src="<?php echo esc_url($this->brand_logo_url()); ?>" alt="Workboard logo"><?php endif; ?></div><p><input type="file" name="logo_file" accept="image/png,image/jpeg,image/webp"></p><p><button class="button button-primary">Upload Logo</button> <?php if($this->brand_logo_url()): ?><button class="button" name="remove_logo" value="1">Remove Logo</button><?php endif; ?></p></form></div>
        <div class="bb-admin-card"><h2>Notifications & Meta WhatsApp</h2><form method="post"><?php wp_nonce_field('bb_backend_settings_save','bb_backend_settings_nonce'); ?><input type="hidden" name="bb_section" value="notifications">
        <?php foreach(['assignment'=>'New task assignment','morning_pending'=>'Morning Pending Tasks Reminder (9:15 AM IST)','email'=>'Email notifications','whatsapp'=>'WhatsApp notifications'] as $k=>$label): $opt=$k==='whatsapp'?'bb_meta_whatsapp_enabled':'bb_notify_'.$k; ?><label class="bb-admin-check"><input type="checkbox" name="<?php echo esc_attr($k); ?>" value="1" <?php checked((int)$g($opt, $k==='email'||$k==='whatsapp'?0:1),1); ?>><?php echo esc_html($label); ?></label><?php endforeach; ?>
        <div class="bb-admin-grid"><label>Phone Number ID<input type="text" name="meta_phone_number_id" value="<?php echo esc_attr($g('bb_meta_whatsapp_phone_number_id','')); ?>"></label><label>Access Token<input type="text" value="Managed securely in Workboard Settings → Messaging" readonly></label><label>Graph API Version<input type="text" name="meta_api_version" value="<?php echo esc_attr($g('bb_meta_whatsapp_api_version','v25.0')); ?>"></label><label>Default Country Code<input type="text" name="meta_country_code" value="<?php echo esc_attr($g('bb_meta_whatsapp_country_code','91')); ?>"></label><label>Template Language<input type="text" value="en" readonly><small>Fixed to English (en).</small></label><label>Test Mobile<input type="text" id="bb-backend-test-mobile" name="meta_test_mobile" value="<?php echo esc_attr($g('bb_meta_whatsapp_test_mobile','')); ?>"><button type="button" class="button" id="bb-backend-test-whatsapp" style="margin-top:6px">Test</button><span id="bb-backend-test-status" style="margin-left:8px"></span></label></div>
        <h3>Meta Templates</h3><?php $templates=['assignment'=>['Task Assigned','workboard_task_assigned'],'update'=>['Task Updated','workboard_task_updated'],'morning'=>['Morning Pending Tasks Reminder','workboard_pending_morningreminder'],'urgent'=>['Urgent Reminder','workboard_urgent_reminder'],'due_reminder'=>['Due Reminder','workboard_due_reminder']]; foreach($templates as $k=>$v): ?><div class="bb-admin-template"><div class="bb-admin-template-head"><label class="bb-admin-check"><input type="checkbox" name="meta_tpl_<?php echo esc_attr($k); ?>_enabled" value="1" <?php checked((int)$g('bb_meta_tpl_'.$k.'_enabled',1),1); ?>><?php echo esc_html($v[0]); ?></label><?php if($k==='morning'): ?><span><button type="button" class="button" id="bb-test-morning-pending">Test</button><span id="bb-test-morning-pending-status" class="bb-morning-test-status"></span></span><?php endif; ?></div><label>Template Name<input type="text" name="meta_tpl_<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($g('bb_meta_tpl_'.$k,$v[1])); ?>"></label></div><?php endforeach; ?>
        <div class="bb-admin-template"><div class="bb-admin-template-head"><label class="bb-admin-check"><input type="checkbox" name="meta_tpl_attendance_summary_enabled" value="1" <?php checked((int)$g('bb_attendance_whatsapp_active',0),1); ?>>Daily Attendance Summary</label></div><label>Template Name<input type="text" name="meta_tpl_attendance_summary" value="<?php echo esc_attr($g('bb_attendance_whatsapp_template','workboard_daily_attendance_summary')); ?>"></label></div>
        <p><button class="button button-primary">Save Notification Settings</button></p></form></div>
        <div class="bb-admin-card"><h2>Telegram Task Inbox</h2>
        <p>Paste a WhatsApp, SMS, email or any other message into your dedicated Telegram group and create a Workboard task without typing the task again. This flow uses the Telegram Bot API only; no AI API is required.</p>
        <form method="post" id="bb-telegram-settings-form"><?php wp_nonce_field('bb_backend_settings_save','bb_backend_settings_nonce'); ?><input type="hidden" name="bb_section" value="telegram">
        <label class="bb-admin-check"><input type="checkbox" name="telegram_enabled" value="1" <?php checked((int)$g('bb_telegram_enabled',0),1); ?>>Enable Telegram Task Inbox</label>
        <div class="bb-admin-grid"><label>Bot Token<input type="password" name="telegram_bot_token" value="" placeholder="<?php echo $g('bb_telegram_bot_token','')?'Configured — leave blank to keep':'Paste token from BotFather'; ?>"></label><label>Allowed Group Chat ID<input type="text" name="telegram_group_chat_id" value="<?php echo esc_attr($g('bb_telegram_group_chat_id','')); ?>" placeholder="Example: -1001234567890"></label><label>Webhook URL<input type="text" readonly value="<?php echo esc_attr(rest_url('business-board/v1/telegram-webhook')); ?>"></label><label>Webhook Secret<input type="text" readonly value="<?php echo esc_attr($g('bb_telegram_webhook_secret','Generated when saved')); ?>"></label></div>
        <div class="bb-telegram-guide" style="margin:18px 0;padding:16px 18px;background:#f8fbff;border:1px solid #c9ddf3;border-left:4px solid #2271b1;border-radius:8px;">
          <h3 style="margin:0 0 10px;">Setup Instructions</h3>
          <ol style="margin:0 0 14px 22px;line-height:1.75;">
            <li>Open Telegram and create a bot through <strong>@BotFather</strong> using <code>/newbot</code>.</li>
            <li>Copy the Bot Token provided by BotFather and paste it into <strong>Bot Token</strong> above.</li>
            <li>Add the new bot to the Telegram group that you want to use as your <strong>Workboard Task Inbox</strong>.</li>
            <li>In BotFather run <code>/setprivacy</code>, select your bot and choose <strong>Disable</strong>. This is required so the bot can read normal pasted messages in the group.</li>
            <li>Tick <strong>Enable Telegram Task Inbox</strong> and click <strong>Save &amp; Connect</strong>.</li>
            <li>In the Telegram group send <code>/chatid</code>. The bot will reply with the Group Chat ID.</li>
            <li>Copy that Group Chat ID into <strong>Allowed Group Chat ID</strong> above, then click <strong>Save &amp; Connect</strong> again.</li>
            <li>Use <strong>Save &amp; Test Group</strong> to confirm that Workboard can send a Telegram message to the group.</li>
          </ol>
          <h3 style="margin:14px 0 8px;">How to Create a Task</h3>
          <div style="line-height:1.75;">Paste the original WhatsApp, email, SMS or other message directly into the connected Telegram group. Do <strong>not</strong> type the task again. The bot will guide you through: <strong>One Time / Recurring → Workspace → Work Area → Assignee → Owner → Priority → Due Date / Time or recurrence details → Reminder → Preview → Create Task</strong>. The original pasted message is retained in the task notes.</div>
          <h3 style="margin:14px 0 8px;">Useful Bot Commands</h3>
          <div style="line-height:1.8;"><code>/chatid</code> – show the current Telegram Group Chat ID<br><code>/cancel</code> – cancel the current task-creation flow<br><code>/help</code> – show bot help<br><code>/start</code> – start / verify the bot in the group</div>
          <p style="margin:14px 0 0;"><strong>Important:</strong> Use a dedicated Telegram Task Inbox group if possible. Any normal message pasted into the connected group can begin the task-creation flow. No OpenAI, Gemini or Claude API is used by this Telegram capture mechanism.</p>
        </div>
        <p><button type="button" class="button button-primary" id="bb-telegram-save-connect">Save &amp; Connect</button> <button type="button" class="button" id="bb-telegram-save-test">Save &amp; Test Group</button> <span id="bb-telegram-action-status" style="display:block;margin-top:10px;font-weight:600" aria-live="polite"></span></p></form></div>
        <div class="bb-admin-card"><h2>AI Settings</h2><form method="post"><?php wp_nonce_field('bb_backend_settings_save','bb_backend_settings_nonce'); ?><input type="hidden" name="bb_section" value="ai"><label class="bb-admin-check"><input type="checkbox" name="enabled" value="1" <?php checked((int)$g('bb_ai_enabled',0),1); ?>>Enable AI Workspace Builder</label><div class="bb-admin-grid"><label>AI Provider<select name="provider"><option value="openai" <?php selected($g('bb_ai_provider','openai'),'openai'); ?>>OpenAI</option><option value="gemini" <?php selected($g('bb_ai_provider','openai'),'gemini'); ?>>Google Gemini</option></select></label><label>Model<input type="text" name="model" value="<?php echo esc_attr($g('bb_ai_model','gpt-5.6')); ?>"></label><label>API Key<input type="password" name="api_key" value="" placeholder="<?php echo $g('bb_ai_api_key','')?'Configured — leave blank to keep':'Enter API key'; ?>"></label><label>Maximum File Size (MB)<input type="number" min="1" max="25" name="max_file_mb" value="<?php echo esc_attr($g('bb_ai_max_file_mb',10)); ?>"></label></div><p><button class="button button-primary">Save AI Settings</button></p></form></div>
        <div class="bb-admin-card"><h2>Backup & Export</h2>
        <p>Download a full JSON backup before cleanup or migration.</p>
        <p><a class="button button-primary" id="bb-admin-download-backup" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=bb_export_backup'),'bb_export_nonce','nonce')); ?>">Download Backup</a></p>
        <hr style="margin:22px 0;border:0;border-top:1px solid #e2e4e7;">
        <h3 style="margin-bottom:8px;">Restore Backup</h3>
        <p>Use a Workboard JSON backup to replace the Workboard data on this site. Network wallet, network messaging configuration and WordPress Media Library files are not restored.</p>
        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="bb-restore-backup-form">
          <input type="hidden" name="action" value="bb_restore_backup">
          <?php wp_nonce_field('bb_restore_nonce','nonce'); ?>
          <p><input type="file" name="backup_file" accept="application/json,.json" required></p>
          <label class="bb-admin-check"><input type="checkbox" name="confirm_replace" value="1" required>Replace existing Workboard data on this site</label>
          <p><button type="submit" class="button button-secondary" id="bb-restore-backup-button">Restore to This Site</button></p>
          <p class="description">A safety backup download of the current site starts automatically before restore. Maximum upload size: <?php echo esc_html(size_format(wp_max_upload_size())); ?>.</p>
        </form>
        </div>
        <script>jQuery(function($){
        $('#bb-backend-test-whatsapp').on('click',function(){var b=$(this),s=$('#bb-backend-test-status'),m=$('#bb-backend-test-mobile').val(); if(!m){s.text('Enter a test mobile number.');return;} b.prop('disabled',true).text('Sending…');s.text(''); $.post(ajaxurl,{action:'bb_test_meta_whatsapp',nonce:'<?php echo esc_js(wp_create_nonce('bb_nonce')); ?>',mobile:m}).done(function(r){s.text(r&&r.data&&r.data.message?r.data.message:(r.success?'Sent':'Failed'));}).fail(function(x){var m=x.responseJSON&&x.responseJSON.data&&x.responseJSON.data.message?x.responseJSON.data.message:''; if(!m){m='Test failed'+(x.status?' (HTTP '+x.status+')':'');} s.text(m);}).always(function(){b.prop('disabled',false).text('Test');});});
        $('#bb-test-morning-pending').on('click',function(){var b=$(this),s=$('#bb-test-morning-pending-status'); if(!window.confirm('Run Morning Pending Tasks Reminder now for this site? This will send the real reminder to every qualifying assignee.'))return; b.prop('disabled',true).text('Running…');s.text(''); $.post(ajaxurl,{action:'bb_test_morning_pending_digest',nonce:'<?php echo esc_js(wp_create_nonce('bb_nonce')); ?>'}).done(function(r){s.text(r&&r.data&&r.data.message?r.data.message:(r.success?'Completed':'Failed'));}).fail(function(x){s.text(x.responseJSON&&x.responseJSON.data&&x.responseJSON.data.message?x.responseJSON.data.message:'Test failed');}).always(function(){b.prop('disabled',false).text('Test');});});
        function telegramSave(test){
            var f=$('#bb-telegram-settings-form'),save=$('#bb-telegram-save-connect'),testBtn=$('#bb-telegram-save-test'),status=$('#bb-telegram-action-status');
            if(!f.length)return;
            var data={action:'bb_save_telegram_settings',nonce:'<?php echo esc_js(wp_create_nonce('bb_nonce')); ?>',telegram_enabled:f.find('[name=telegram_enabled]').is(':checked')?1:0,telegram_bot_token:f.find('[name=telegram_bot_token]').val()||'',telegram_group_chat_id:f.find('[name=telegram_group_chat_id]').val()||'',test_telegram:test?1:0};
            save.prop('disabled',true);testBtn.prop('disabled',true);status.css('color','#50575e').text(test?'Saving, connecting and testing…':'Saving and connecting…');
            $.post(ajaxurl,data).done(function(r){var msg=r&&r.data&&r.data.message?r.data.message:(r&&r.success?'Telegram settings saved.':'Could not save Telegram settings.');status.css('color',r&&r.success?'#008a20':'#b32d2e').text(msg);if(r&&r.success&&data.telegram_bot_token){f.find('[name=telegram_bot_token]').val('').attr('placeholder','Configured — leave blank to keep');}}).fail(function(x){var msg=x.responseJSON&&x.responseJSON.data&&x.responseJSON.data.message?x.responseJSON.data.message:'Telegram request failed. Please check the Bot Token, Group Chat ID and server outbound HTTPS access.';status.css('color','#b32d2e').text(msg);}).always(function(){save.prop('disabled',false);testBtn.prop('disabled',false);});
        }
        $('#bb-telegram-save-connect').on('click',function(){telegramSave(false);});
        $('#bb-telegram-save-test').on('click',function(){telegramSave(true);});
        $('#bb-restore-backup-form').on('submit',function(e){
            var form=this,$form=$(form),file=$form.find('[name=backup_file]')[0],confirmed=$form.find('[name=confirm_replace]').is(':checked');
            if(!file||!file.files||!file.files.length||!confirmed)return;
            e.preventDefault();
            if(!window.confirm('Restore this backup and REPLACE all Workboard data on this site? This cannot be undone from the database after restore.'))return;
            var btn=$('#bb-restore-backup-button'); btn.prop('disabled',true).text('Downloading safety backup…');
            var frame=document.createElement('iframe'); frame.style.display='none'; frame.src=<?php echo wp_json_encode(wp_nonce_url(admin_url('admin-post.php?action=bb_export_backup'),'bb_export_nonce','nonce')); ?>; document.body.appendChild(frame);
            window.setTimeout(function(){btn.text('Restoring…'); form.submit();},1600);
        });
        });</script>
        </div><?php
    }

    public function block_business_board_backend(){
        if(!is_user_logged_in() || current_user_can('manage_options') || wp_doing_ajax()) return;
        $u=wp_get_current_user();
        if($this->current_employee() || $this->current_party() || in_array('bb_employee',(array)$u->roles,true) || in_array('bb_party',(array)$u->roles,true)){ wp_safe_redirect($this->frontend_url()); exit; }
    }
    public function hide_business_board_admin_bar($show){
        // The Workboard frontend is a standalone app shell. Never reserve
        // WordPress admin-bar space there, including for real WP administrators.
        if ($this->is_business_board_frontend()) return false;
        if(!is_user_logged_in() || current_user_can('manage_options')) return $show;
        $u=wp_get_current_user(); return ($this->current_employee()||$this->current_party()||in_array('bb_employee',(array)$u->roles,true)||in_array('bb_party',(array)$u->roles,true))?false:$show;
    }
    public function notespace_excel_footer_fallback(){
        if(!$this->is_business_board_frontend()) return;
        ?>
        <style id="bb-notespace-excel-fallback-style">
        .bb-notespace-toolbar .bb-notespace-excel-forced{
            display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:6px!important;
            min-height:40px!important;padding:0 14px!important;border:1px solid #86c99a!important;border-radius:9px!important;
            background:#f0fdf4!important;color:#166534!important;font-weight:800!important;white-space:nowrap!important;cursor:pointer!important;
        }
        .bb-notespace-toolbar .bb-notespace-excel-forced:hover{background:#dcfce7!important;border-color:#4ade80!important}
        @media(max-width:768px){
          .bb-notespace-toolbar .bb-notespace-excel-forced{width:42px!important;min-width:42px!important;padding:0!important}
          .bb-notespace-toolbar .bb-notespace-excel-forced .bb-excel-label{display:none!important}
        }
        </style>
        <script id="bb-notespace-excel-fallback-script">
        (function(){
          function exportUrl(id){
            if(!window.BB || !BB.adminPost || !BB.exportNonce || !id) return '';
            var q=new URLSearchParams({action:'bb_export_notespace_excel',notespace_id:String(id),nonce:String(BB.exportNonce)});
            return BB.adminPost+'?'+q.toString();
          }
          function ensure(){
            var toolbar=document.querySelector('.bb-notespace-toolbar');
            if(!toolbar) return;
            var existing=toolbar.querySelector('[data-export-notespace-excel]');
            if(existing){
              existing.style.setProperty('display','inline-flex','important');
              return;
            }
            var shell=toolbar.closest('.bb-notespace-board-shell');
            var add=toolbar.querySelector('[data-add-note-area]');
            var id=(toolbar.getAttribute('data-active-notespace')||(shell&&shell.getAttribute('data-active-notespace'))||(add&&add.getAttribute('data-notespace'))||'').trim();
            if(!id) return;
            var btn=document.createElement('button');
            btn.type='button';
            btn.className='bb-notespace-excel-forced';
            btn.setAttribute('data-export-notespace-excel',id);
            btn.setAttribute('title','Download Excel');
            btn.setAttribute('aria-label','Download Excel');
            btn.innerHTML='<svg class="bb-excel-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 3.5h10v17H4z" fill="#107C41"/><path d="M14 6h6v14.5h-6z" fill="#21A366"/><path d="M7.3 8.2 10 12l-2.8 3.8h2.1l1.8-2.6 1.8 2.6H15L12.2 12 15 8.2h-2.1l-1.8 2.6-1.8-2.6z" fill="#fff"/><path d="M16 8.5h2.5M16 11.5h2.5M16 14.5h2.5M16 17.5h2.5" stroke="#fff" stroke-width="1.1" stroke-linecap="round"/></svg><span class="bb-excel-label">Excel</span>';
            btn.addEventListener('click',function(ev){
              ev.preventDefault();ev.stopPropagation();
              var u=exportUrl(id);if(u) window.location.href=u;
            });
            if(add) toolbar.insertBefore(btn,add); else toolbar.appendChild(btn);
          }
          function start(){
            ensure();
            if(document.body){new MutationObserver(ensure).observe(document.body,{childList:true,subtree:true});}
            window.setInterval(ensure,1000);
          }
          if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',start); else start();
        })();
        </script>
        <?php
    }

    public function send_frontend_nocache_headers(){
        if(!$this->is_business_board_frontend()) return;
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }

    public function enqueue_admin($hook){ if($hook==='toplevel_page_business-board') $this->enqueue_assets(); elseif($hook==='settings_page_workboard-settings') wp_enqueue_script('jquery'); }
    private function enqueue_assets(){
        $css_file = plugin_dir_path(__FILE__).'assets/css/business-board.css';
        $js_file  = plugin_dir_path(__FILE__).'assets/js/business-board.js';
        $css_ver  = self::VERSION.'-'.(file_exists($css_file) ? filemtime($css_file) : '0');
        $js_ver   = self::VERSION.'-'.(file_exists($js_file) ? filemtime($js_file) : '0');
        wp_enqueue_style('bb-style',plugins_url('assets/css/business-board.css',__FILE__),[],$css_ver);
        wp_enqueue_script('bb-script',plugins_url('assets/js/business-board.js',__FILE__),['jquery'],$js_ver,true);
        // v4.12.64: robust Notespace Excel control fallback. This inline helper ensures
        // the button is present even when an intermediary cache serves an older app JS file.
        wp_add_inline_script('bb-script', <<<'JS'
(function(){
  function ensureNotespaceExcelButton(){
    var toolbar=document.querySelector('.bb-notespace-toolbar');
    if(!toolbar || toolbar.querySelector('[data-export-notespace-excel]')) return;
    var add=toolbar.querySelector('[data-add-note-area]');
    var id=add && add.getAttribute('data-notespace');
    if(!id) return;
    var btn=document.createElement('button');
    btn.type='button';
    btn.className='bb-btn light bb-notespace-excel-btn bb-notespace-excel-fallback';
    btn.setAttribute('data-export-notespace-excel',id);
    btn.setAttribute('title','Download this Notespace in Excel');
    btn.setAttribute('aria-label','Download this Notespace in Excel');
    btn.innerHTML='<svg class="bb-excel-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 3.5h10v17H4z" fill="#107C41"/><path d="M14 6h6v14.5h-6z" fill="#21A366"/><path d="M7.3 8.2 10 12l-2.8 3.8h2.1l1.8-2.6 1.8 2.6H15L12.2 12 15 8.2h-2.1l-1.8 2.6-1.8-2.6z" fill="#fff"/><path d="M16 8.5h2.5M16 11.5h2.5M16 14.5h2.5M16 17.5h2.5" stroke="#fff" stroke-width="1.1" stroke-linecap="round"/></svg><span class="bb-excel-btn-text">Excel</span>';
    toolbar.insertBefore(btn,add);
    btn.addEventListener('click',function(e){
      e.preventDefault();
      if(!window.BB || !BB.adminPost || !BB.exportNonce) return;
      var q=new URLSearchParams({action:'bb_export_notespace_excel',notespace_id:String(id),nonce:String(BB.exportNonce)});
      window.location.href=BB.adminPost+'?'+q.toString();
    });
  }
  function boot(){
    ensureNotespaceExcelButton();
    if(!document.body) return;
    new MutationObserver(ensureNotespaceExcelButton).observe(document.body,{childList:true,subtree:true});
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',boot); else boot();
})();
JS
        , 'after');
        $ctx_file = plugin_dir_path(__FILE__).'assets/js/business-board-context-actions.js';
        $ctx_ver  = self::VERSION.'-'.(file_exists($ctx_file) ? filemtime($ctx_file) : '0');
        wp_enqueue_script('bb-context-actions',plugins_url('assets/js/business-board-context-actions.js',__FILE__),['bb-script'],$ctx_ver,true);

        // v4.17.0: React Web migration foundation. WordPress ships React via wp-element,
        // so the plugin does not need a second React runtime or a CDN dependency.
        $react_file = plugin_dir_path(__FILE__).'assets/js/business-board-react.js';
        $react_ver  = self::VERSION.'-'.(file_exists($react_file) ? filemtime($react_file) : '0');
        wp_enqueue_script('bb-react-web',plugins_url('assets/js/business-board-react.js',__FILE__),['wp-element','bb-script'],$react_ver,true);
        wp_localize_script('bb-react-web','BBReactConfig',[
            'version'=>self::VERSION,
            'wordmarkUrl'=>plugin_dir_url(__FILE__).'assets/img/workboard-header-wordmark.png?v='.self::VERSION,
        ]);
        wp_localize_script('bb-script','BB',[
            'ajax'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('bb_nonce'),
            'today'=>current_time('Y-m-d'),'canManage'=>$this->is_privileged_user(),'canManageAccess'=>$this->is_privileged_user(),
            'maxUpload'=>wp_max_upload_size(),'adminPost'=>admin_url('admin-post.php'),'exportNonce'=>wp_create_nonce('bb_export_nonce'),'home'=>$this->frontend_url(),
            'isFrontend'=>$this->is_business_board_frontend()?1:0,
            'initialView'=>(is_page(absint(get_option('bb_pending_tasks_page_id')))?'pending-tasks':(is_page(absint(get_option('bb_labels_page_id')))?'labels':(is_page(absint(get_option('bb_workspaces_page_id')))?'workspaces':sanitize_key((string)get_query_var('bb_view'))))),
            'viewRoutes'=>[
                'dashboard'=>home_url('/dashboard/'),
                'workspaces'=>home_url('/workspaces/'),
                'folders'=>home_url('/folder/'),
                'focus'=>home_url('/focus/'),
                'pending-tasks'=>home_url('/pending-tasks/'),
                'daily-scoreboard'=>home_url('/daily-scoreboard/'),
                'pending-billing'=>home_url('/pending-billing/'),
                'billed-master'=>home_url('/billed-master/'),
                'templates'=>home_url('/workspace-templates/'),
                'work-area-templates'=>home_url('/work-area-templates/'),
                'employees'=>home_url('/users/'),
                'parties'=>home_url('/parties/'),
                'attendspace'=>home_url('/attendspace/'),
                'attendance-register'=>home_url('/attendance-register/'),
                'monthly-sheet'=>home_url('/monthly-attendance/'),
                'leave'=>home_url('/leave/'),
                'pending-leave'=>home_url('/pending-leave-approval/'),
                'attendance-settings'=>home_url('/attendance-settings/'),
                'departments'=>home_url('/departments/'),
                'designations'=>home_url('/designations/'),
                'labels'=>home_url('/labels/'),
                'holidays'=>home_url('/holidays/'),
                'settings'=>home_url('/settings/'),
                'activity'=>home_url('/activity-log/'),
                'timesheet'=>home_url('/daily-activity/'),
                'notespaces'=>home_url('/notespaces/'),
                'mathspaces'=>home_url('/mathspaces/'),
                'callspace'=>home_url('/callspace/'),
                'docspace'=>home_url('/docspace/'),
            ],
            'currentRouteUrl'=>$this->is_business_board_frontend()?$this->current_frontend_route_url():$this->frontend_url(),
            'isLoginPage'=>is_page(absint(get_option('bb_login_page_id')))?1:0,
            'workspaceSlug'=>sanitize_title((string)get_query_var('bb_workspace_slug')),'workspaceBase'=>home_url('/workspaces/'),
            'taskSlug'=>sanitize_title((string)get_query_var('bb_task_slug')),'taskBase'=>home_url('/business-board/task/')
        ]);
    }
    public function render_admin(){ echo '<div class="wrap bb-wrap">'.$this->app_shell().'</div>'; }
    public function shortcode(){
        $this->enqueue_assets();
        if(!is_user_logged_in()) return '<div class="bb-shortcode-wrap">'.$this->login_shell().'</div>';
        return '<div class="bb-shortcode-wrap">'.$this->app_shell().'</div>';
    }
    public function login_shortcode(){ $this->enqueue_assets(); return is_user_logged_in()?'<div class="bb-login-card"><h2>You are already logged in.</h2><a class="bb-btn primary" href="'.esc_url($this->frontend_url()).'">Open Workboard</a></div>':$this->login_shell(); }
    private function brand_logo_site_id(){
        if(is_multisite() && function_exists('get_main_site_id')){
            $id=(int)get_main_site_id();
            if($id>0) return $id;
        }
        return (int)get_current_blog_id();
    }

    private function brand_logo_url(){
        $target=$this->brand_logo_site_id();
        $switched=is_multisite() && get_current_blog_id()!==$target;
        if($switched) switch_to_blog($target);
        $id=absint(get_option('bb_brand_logo_id',0));
        $url=$id ? wp_get_attachment_image_url($id,'full') : '';
        if($switched) restore_current_blog();
        return $url ? (string)$url : '';
    }

    private function delete_brand_logo_id(){
        $target=$this->brand_logo_site_id();
        $switched=is_multisite() && get_current_blog_id()!==$target;
        if($switched) switch_to_blog($target);
        delete_option('bb_brand_logo_id');
        if($switched) restore_current_blog();
    }

    private function upload_brand_logo($field='logo_file'){
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/image.php';
        require_once ABSPATH.'wp-admin/includes/media.php';
        $target=$this->brand_logo_site_id();
        $switched=is_multisite() && get_current_blog_id()!==$target;
        if($switched) switch_to_blog($target);
        $id=media_handle_upload($field,0);
        if(is_wp_error($id)){
            if($switched) restore_current_blog();
            return $id;
        }
        update_option('bb_brand_logo_id',(int)$id);
        $url=wp_get_attachment_image_url((int)$id,'full');
        if($switched) restore_current_blog();
        return ['id'=>(int)$id,'url'=>$url ? (string)$url : ''];
    }

    private function brand_logo_markup($class='bb-brand-logo-img'){
        $url=$this->brand_logo_url();
        if($url) return '<img class="'.esc_attr($class).'" src="'.esc_url($url).'" alt="Workboard logo">';
        return '<span class="bb-brand-logo-fallback">B</span>';
    }

    // Site-level organization profile logo. This is intentionally separate from
    // the network Workboard branding logo used by the app shell/login page.
    private function org_logo_url(){
        $id=absint(get_option('bb_org_logo_id',0));
        if(!$id) return '';
        $url=wp_get_attachment_image_url($id,'full');
        return $url ? (string)$url : '';
    }

    private function upload_org_logo($field='org_logo_file'){
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/image.php';
        require_once ABSPATH.'wp-admin/includes/media.php';
        $old_id=absint(get_option('bb_org_logo_id',0));
        $id=media_handle_upload($field,0);
        if(is_wp_error($id)) return $id;
        update_option('bb_org_logo_id',(int)$id);
        // Do not delete an older media item automatically; it may be referenced elsewhere.
        $url=wp_get_attachment_image_url((int)$id,'full');
        return ['id'=>(int)$id,'url'=>$url ? (string)$url : ''];
    }

    private function login_shell(){ ob_start(); ?>
        <div class="bb-login-page">
            <div class="bb-login-layout">
                <div class="bb-login-brand-panel">
                    <div class="bb-login-brand">
                        <div class="bb-logo bb-login-logo-full">
                            <img class="bb-login-logo-image" src="<?php echo esc_url(plugins_url('assets/images/workboard-login-logo.png', __FILE__)); ?>" alt="WorkBoard">
                        </div>
                    </div>
                </div>
                <div class="bb-login-form-panel">
                    <div class="bb-login-card">
                        <form id="bb-user-login" class="bb-login-form">
                            <h2>Welcome back</h2>
                            <label>Login ID / Email / User Name<input name="login" autocomplete="username" required></label>
                            <label>Password
                                <span class="bb-password-wrap">
                                    <input type="password" name="password" autocomplete="current-password" required>
                                    <button type="button" class="bb-password-toggle" aria-label="Show password" title="Show password" aria-pressed="false" onclick="var i=this.parentNode.querySelector('input[name=&quot;password&quot;]');if(!i)return false;var s=i.type==='password';i.type=s?'text':'password';this.classList.toggle('is-visible',s);this.setAttribute('aria-pressed',s?'true':'false');this.setAttribute('aria-label',s?'Hide password':'Show password');this.setAttribute('title',s?'Hide password':'Show password');i.focus();return false;">
                                        <svg class="bb-eye-show" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5c-5.5 0-9.5 4.7-10.7 6.3a1.1 1.1 0 0 0 0 1.4C2.5 14.3 6.5 19 12 19s9.5-4.7 10.7-6.3a1.1 1.1 0 0 0 0-1.4C21.5 9.7 17.5 5 12 5Zm0 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8Zm0-2a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z"/></svg>
                                        <svg class="bb-eye-hide" viewBox="0 0 24 24" aria-hidden="true"><path d="m3.3 2 18.7 18.7-1.3 1.3-3.1-3.1A10.9 10.9 0 0 1 12 20C6.5 20 2.5 15.3 1.3 13.7a1.1 1.1 0 0 1 0-1.4A18.2 18.2 0 0 1 5 8.4L2 5.3 3.3 2ZM7.8 11.2A4 4 0 0 0 12.8 16l-5-4.8ZM12 6c5.5 0 9.5 4.7 10.7 6.3.3.4.3 1 0 1.4a17 17 0 0 1-2.5 2.8l-2.1-2.1A4 4 0 0 0 9.6 6.5 10.4 10.4 0 0 1 12 6Z"/></svg>
                                    </button>
                                </span>
                            </label>
                            <button class="bb-btn primary">Log In</button>
                            <div class="bb-login-error"></div>
                        </form>
                        <script>
                        (function(){
                          var form=document.getElementById('bb-user-login');
                          if(!form || form.dataset.passwordToggleBound==='1') return;
                          form.dataset.passwordToggleBound='1';
                          form.addEventListener('click',function(e){
                            var btn=e.target.closest('.bb-password-toggle');
                            if(!btn || !form.contains(btn)) return;
                            e.preventDefault();
                            var wrap=btn.closest('.bb-password-wrap');
                            var input=wrap ? wrap.querySelector('input[name="password"]') : null;
                            if(!input) return;
                            var show=input.type==='password';
                            input.type=show?'text':'password';
                            btn.classList.toggle('is-visible',show);
                            btn.setAttribute('aria-pressed',show?'true':'false');
                            btn.setAttribute('aria-label',show?'Hide password':'Show password');
                            btn.setAttribute('title',show?'Hide password':'Show password');
                            input.focus({preventScroll:true});
                          });
                        })();
                        </script>
                    </div>
                </div>
            </div>
        </div>
    <?php return ob_get_clean(); }

    private function app_shell(){ ob_start(); ?>
        <style id="bb-no-legacy-header-search">
            #bb-global-search,.bb-global-search,.bb-topbar input[type="search"],.bb-top-actions input[type="search"]{display:none!important;visibility:hidden!important;width:0!important;min-width:0!important;max-width:0!important;height:0!important;margin:0!important;padding:0!important;border:0!important;overflow:hidden!important;pointer-events:none!important}
        </style>
        <script id="bb-purge-legacy-header-search">
        (function(){
            function purgeWorkboardHeaderSearch(){
                document.querySelectorAll('#bb-global-search,.bb-global-search,.bb-topbar input[type="search"],.bb-top-actions input[type="search"]').forEach(function(el){el.remove();});
            }
            purgeWorkboardHeaderSearch();
            document.addEventListener('DOMContentLoaded',purgeWorkboardHeaderSearch);
            window.addEventListener('pageshow',purgeWorkboardHeaderSearch);
            if(window.MutationObserver){new MutationObserver(purgeWorkboardHeaderSearch).observe(document.documentElement,{childList:true,subtree:true});}
        })();
        </script>
        <div id="bb-app" class="bb-app" data-workboard-version="<?php echo esc_attr(self::VERSION); ?>">
            <?php $bb_sidebar_org_name = trim((string)get_option('bb_org_name','')); if($bb_sidebar_org_name==='') $bb_sidebar_org_name='Org Name'; ?>
            <aside class="bb-sidebar">
                <div class="bb-mobile-sidebar-head" aria-hidden="true"><strong class="bb-mobile-org-name"><?php echo esc_html($bb_sidebar_org_name); ?></strong><button type="button" class="bb-mobile-menu-close" aria-label="Hide menu" title="Hide menu">×</button></div>
                <div class="bb-sidebar-org" title="<?php echo esc_attr($bb_sidebar_org_name); ?>"><strong class="bb-sidebar-org-name"><?php echo esc_html($bb_sidebar_org_name); ?></strong></div>
                <nav class="bb-nav bb-nav-sectioned">
                    <button class="bb-nav-standalone-dashboard active" data-view="dashboard"><span class="bb-nav-bullet">•</span>Dashboard</button>
                    <div class="bb-nav-section is-collapsed" data-nav-section="work">
                        <div class="bb-nav-section-title" role="button" tabindex="0" aria-expanded="false"><span>Work</span><span class="bb-nav-section-caret" aria-hidden="true">⌄</span></div>
                        <div class="bb-nav-section-items">
                            <button data-view="workspaces"><span class="bb-nav-bullet">•</span>Workspaces</button>
                            <button data-view="folders"><span class="bb-nav-bullet">•</span>Folders</button>
                            <button data-view="focus"><span class="bb-nav-bullet">•</span>Focus</button>
                            <button data-view="pending-tasks"><span class="bb-nav-bullet">•</span>Pending Tasks</button>
                            <button data-view="daily-scoreboard"><span class="bb-nav-bullet">•</span>Daily Scoreboard</button>
                            <button data-view="activity"><span class="bb-nav-bullet">•</span>Activity Log</button>
                            <div class="bb-nav-subsection" data-nav-subsection="billing" <?php echo (int)get_option('bb_show_module_billing',0)===1?'':'style="display:none"'; ?>>
                                <div class="bb-nav-subsection-title" role="button" tabindex="0" aria-expanded="true"><span>Billing</span><span class="bb-nav-subsection-caret" aria-hidden="true">⌄</span></div>
                                <div class="bb-nav-subsection-items">
                                    <button data-view="pending-billing"><span class="bb-nav-bullet">•</span>Pending Billing</button>
                                    <button data-view="billed-master"><span class="bb-nav-bullet">•</span>Billed Master</button>
                                </div>
                            </div>
                            <div class="bb-nav-subsection" data-nav-subsection="timesheet" <?php echo (int)get_option('bb_show_module_timesheet',0)===1?'':'style="display:none"'; ?>>
                                <div class="bb-nav-subsection-title" role="button" tabindex="0" aria-expanded="true"><span>Time Sheet</span><span class="bb-nav-subsection-caret" aria-hidden="true">⌄</span></div>
                                <div class="bb-nav-subsection-items">
                                    <button data-view="timesheet"><span class="bb-nav-bullet">•</span>Time Sheet</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php if((int)get_option('bb_show_module_callspace',0)===1): ?>
                    <div class="bb-nav-section is-collapsed" data-nav-section="callspace">
                        <div class="bb-nav-section-title" role="button" tabindex="0" aria-expanded="false"><span>CallSpace</span><span class="bb-nav-section-caret" aria-hidden="true">⌄</span></div>
                        <div class="bb-nav-section-items">
                            <button data-view="callspace"><span class="bb-nav-bullet">•</span>CallSpace</button>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="bb-nav-section is-collapsed" data-nav-section="hr" <?php echo ((int)get_option('bb_show_module_attendance',1)===1 || (int)get_option('bb_show_module_leave',1)===1)?'':'style="display:none"'; ?>>
                        <div class="bb-nav-section-title" role="button" tabindex="0" aria-expanded="false"><span>HR</span><span class="bb-nav-section-caret" aria-hidden="true">⌄</span></div>
                        <div class="bb-nav-section-items">
                            <div class="bb-nav-subsection" data-nav-subsection="attendance" <?php echo (int)get_option('bb_show_module_attendance',1)===1?'':'style="display:none"'; ?>>
                                <div class="bb-nav-subsection-title" role="button" tabindex="0" aria-expanded="true"><span>Attendance</span><span class="bb-nav-subsection-caret" aria-hidden="true">⌄</span></div>
                                <div class="bb-nav-subsection-items">
                                    <button data-view="attendspace"><span class="bb-nav-bullet">•</span>AttendSpace</button>
                                    <button data-view="attendance-register"><span class="bb-nav-bullet">•</span>Attendance Register</button>
                                    <button data-view="monthly-sheet"><span class="bb-nav-bullet">•</span>Monthly Sheet</button>
                                    <button data-view="holidays"><span class="bb-nav-bullet">•</span>Holiday</button>
                                    <button data-view="attendance-settings"><span class="bb-nav-bullet">•</span>Attendance Settings</button>
                                </div>
                            </div>
                            <div class="bb-nav-subsection" data-nav-subsection="leave" <?php echo (int)get_option('bb_show_module_leave',1)===1?'':'style="display:none"'; ?>>
                                <div class="bb-nav-subsection-title" role="button" tabindex="0" aria-expanded="true"><span>Leave</span><span class="bb-nav-subsection-caret" aria-hidden="true">⌄</span></div>
                                <div class="bb-nav-subsection-items">
                                    <button data-view="leave"><span class="bb-nav-bullet">•</span>Leave</button>
                                    <button data-view="pending-leave"><span class="bb-nav-bullet">•</span>Pending Leave Approval</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php if((int)get_option('bb_show_module_notes',1)===1): ?>
                    <div class="bb-nav-section is-collapsed" data-nav-section="notes">
                        <div class="bb-nav-section-title" role="button" tabindex="0" aria-expanded="false"><span>Notes</span><span class="bb-nav-section-caret" aria-hidden="true">⌄</span></div>
                        <div class="bb-nav-section-items">
                            <button data-view="notespaces"><span class="bb-nav-bullet">•</span>Notespaces</button>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if((int)get_option('bb_show_module_math',1)===1): ?>
                    <div class="bb-nav-section is-collapsed" data-nav-section="math">
                        <div class="bb-nav-section-title" role="button" tabindex="0" aria-expanded="false"><span>Math</span><span class="bb-nav-section-caret" aria-hidden="true">⌄</span></div>
                        <div class="bb-nav-section-items">
                            <button data-view="mathspaces"><span class="bb-nav-bullet">•</span>MathSpaces</button>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if((int)get_option('bb_show_module_docspace',0)===1): ?>
                    <div class="bb-nav-section is-collapsed" data-nav-section="docspace">
                        <div class="bb-nav-section-title" role="button" tabindex="0" aria-expanded="false"><span>DocSpace</span><span class="bb-nav-section-caret" aria-hidden="true">⌄</span></div>
                        <div class="bb-nav-section-items">
                            <button data-view="docspace"><span class="bb-nav-bullet">•</span>DocSpace</button>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="bb-nav-section is-collapsed" data-nav-section="masters">
                        <div class="bb-nav-section-title" role="button" tabindex="0" aria-expanded="false"><span>Masters</span><span class="bb-nav-section-caret" aria-hidden="true">⌄</span></div>
                        <div class="bb-nav-section-items">
                            <button data-view="employees"><span class="bb-nav-bullet">•</span>Users</button>
                            <button data-view="parties"><span class="bb-nav-bullet">•</span>Parties</button>
                            <button data-view="departments"><span class="bb-nav-bullet">•</span>Departments</button>
                            <button data-view="designations"><span class="bb-nav-bullet">•</span>Designations</button>
                            <button data-view="labels"><span class="bb-nav-bullet">•</span>Labels</button>
                            <button data-view="templates"><span class="bb-nav-bullet">•</span>Workspace Templates</button>
                            <button data-view="work-area-templates"><span class="bb-nav-bullet">•</span>Work Area Templates</button>
                            <button data-view="settings"><span class="bb-nav-bullet">•</span>Settings</button>
                        </div>
                    </div>
                </nav>
                <div class="bb-user"><div><strong><?php echo esc_html(wp_get_current_user()->display_name ?: 'Administrator'); ?></strong><small id="bb-login-mode"><?php echo current_user_can('manage_options')?'Administrator':'Workboard User'; ?></small></div><button type="button" class="bb-logout-btn" title="Log Off" aria-label="Log Off"><span class="bb-logout-icon">⏻</span><span class="bb-logout-text">Log Off</span></button></div>
            </aside>
            <main class="bb-main">
                <header class="bb-topbar"><div class="bb-topbar-left"><button type="button" class="bb-sidebar-toggle" aria-label="Collapse menu" title="Show / hide menu">☰</button><button type="button" class="bb-mobile-menu" aria-label="Show menu" title="Show menu" aria-expanded="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button><div><h1 id="bb-page-title">Workspaces</h1><p id="bb-page-subtitle">Select a Workspace to open its operational board</p></div></div><div class="bb-top-actions"><button class="bb-icon-btn bb-mobile-analysis-toggle" type="button" aria-label="Workspace analysis" title="Workspace analysis"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19h16M6 16V9m6 7V5m6 11v-4"/></svg></button><button class="bb-icon-btn bb-mobile-add-area" type="button" aria-label="Add Work Area" title="Add Work Area"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></button><button class="bb-icon-btn bb-mobile-filter-top" type="button" aria-label="Filters" title="Filters"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16l-6.5 7.2V18l-3 1v-6.8L4 5z"/></svg></button><button class="bb-icon-btn bb-top-filter-reset" type="button" aria-label="Clear filters" title="Clear filters" hidden><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button><button class="bb-icon-btn bb-workspace-top-toggle" type="button" aria-label="Hide workspace controls" title="Hide workspace controls" hidden><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10l5 5 5-5"/></svg></button><div class="bb-header-logo" title="WorkBoard"><img class="bb-header-logo-img bb-header-wordmark-img" src="<?php echo esc_url(plugin_dir_url(__FILE__).'assets/img/workboard-header-wordmark.png?v='.self::VERSION); ?>" alt="WorkBoard"></div></div></header>
                <section id="bb-content" class="bb-content"><div class="bb-loading">Loading Workboard…</div></section>
            </main>
            <div class="bb-mobile-backdrop"></div>
        </div>
        <div id="bb-modal" class="bb-modal" aria-hidden="true"><div class="bb-modal-card"><button class="bb-modal-close">×</button><div id="bb-modal-content"></div></div></div>
    <?php return ob_get_clean(); }

    public function ajax_refresh_nonce(){
        if(!is_user_logged_in()) wp_send_json_error(['message'=>'Login required'],403);
        nocache_headers();
        wp_send_json_success(['nonce'=>wp_create_nonce('bb_nonce')]);
    }

    private function verify(){ check_ajax_referer('bb_nonce','nonce'); if(!is_user_logged_in()) wp_send_json_error(['message'=>'Login required'],403); }
    private function current_employee(){
        global $wpdb; if(current_user_can('manage_options')) return null;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['employees']} WHERE wp_user_id=%d LIMIT 1",get_current_user_id()));
    }
    private function current_party(){
        global $wpdb; if(current_user_can('manage_options')) return null;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['parties']} WHERE wp_user_id=%d AND login_enabled=1 AND status='active' LIMIT 1",get_current_user_id()));
    }
    private function is_privileged_user(){
        if(current_user_can('manage_options')) return true;
        $e=$this->current_employee();
        return $e && in_array(strtolower((string)($e->system_role??'employee')),['owner','manager'],true);
    }
    private function can_reopen_task(){
        if(current_user_can('manage_options')) return true;
        $e=$this->current_employee();
        return $e && strtolower((string)($e->system_role??'employee'))==='manager';
    }
    private function require_manage(){ if(!$this->is_privileged_user()) wp_send_json_error(['message'=>'Owner or Manager access required'],403); }

    private function identity(){
        if(current_user_can('manage_options')) return ['mode'=>'admin','label'=>'Administrator','employee_id'=>0,'can_manage'=>1,'role'=>'owner'];
        if($party=$this->current_party()) return ['mode'=>'party','label'=>$party->party_name,'employee_id'=>0,'party_id'=>(int)$party->id,'can_manage'=>0,'role'=>'party'];
        if($e=$this->current_employee()){
            $role=strtolower((string)($e->system_role??'employee'));
            if(!in_array($role,['owner','manager','employee'],true)) $role='employee';
            return ['mode'=>'user','label'=>$e->name,'employee_id'=>(int)$e->id,'can_manage'=>in_array($role,['owner','manager'],true)?1:0,'role'=>$role];
        }
        return ['mode'=>'user','label'=>wp_get_current_user()->display_name,'employee_id'=>0,'can_manage'=>0,'role'=>'employee'];
    }
    private function assignment_rights_rank($rights){ return $rights==='view_edit'?3:($rights==='edit'?2:($rights==='view'?1:0)); }
    private function employee_entity_rights($employee_id,$entity_type,$entity_id){
        global $wpdb; $rows=$wpdb->get_results($wpdb->prepare("SELECT rights,relation_type FROM {$this->tables['entity_assignments']} WHERE employee_id=%d AND entity_type=%s AND entity_id=%d",$employee_id,$entity_type,$entity_id));
        $rank=0; $relations=[]; foreach($rows as $r){ $relations[]=$r->relation_type; $rank=max($rank,$r->relation_type==='owner'?3:($r->relation_type==='assignee'?1:0)); }
        return ['rank'=>$rank,'relations'=>$relations];
    }
    private function task_owner_can_share($task_id){
        if($this->is_privileged_user()) return true;
        global $wpdb;
        $task=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d",$task_id));
        if(!$task) return false;
        $emp=$this->current_employee(); if(!$emp) return false;
        $wa_actions=$this->employee_area_action_rights((int)$emp->id,'work_area',(int)$task->work_area_id);
        if(in_array('delete',$wa_actions,true)) return true;
        foreach([['workspace',$task->workspace_id],['work_area',$task->work_area_id],['task',$task->id]] as [$type,$id]){
            if(!$id) continue;
            $r=$this->employee_entity_rights($emp->id,$type,$id);
            if($r['rank']>=2 && in_array('owner',$r['relations'],true)) return true;
        }
        return false;
    }

    private function party_area_rights($party_id,$work_area_id){
        global $wpdb; $raw=(string)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(NULLIF(rights,''),'view') FROM {$this->tables['work_area_parties']} WHERE work_area_id=%d AND party_id=%d LIMIT 1",$work_area_id,$party_id));
        $rights=$this->normalize_area_action_rights($raw); return array_values(array_unique($rights));
    }
    private function party_area_can($party_id,$work_area_id,$cap='view'){
        $map=['view'=>'view','add'=>'add','create'=>'add','edit'=>'edit','update'=>'edit','delete'=>'delete','complete'=>'edit']; $need=$map[$cap]??'view'; return in_array($need,$this->party_area_rights($party_id,$work_area_id),true);
    }
    private function task_permission($task_id,$cap='view'){
        if($this->is_privileged_user()) return true; global $wpdb;
        if($party=$this->current_party()){ $wa=(int)$wpdb->get_var($wpdb->prepare("SELECT work_area_id FROM {$this->tables['tasks']} WHERE id=%d",$task_id)); return $wa && $this->party_area_can((int)$party->id,$wa,$cap); }
        $task=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d",$task_id)); if(!$task) return false;
        $emp=$this->current_employee(); if(!$emp) return false;
        $wa_actions=$this->employee_area_action_rights((int)$emp->id,'work_area',(int)$task->work_area_id);
        if($cap==='view' && in_array('view',$wa_actions,true)) return true;
        if($cap==='edit' && in_array('edit',$wa_actions,true)) return true;
        if($cap==='complete' && in_array('edit',$wa_actions,true)) return true;
        $rank=0; $rels=[];
        foreach([['workspace',$task->workspace_id],['work_area',$task->work_area_id],['task',$task->id]] as [$type,$id]){ if(!$id)continue; $r=$this->employee_entity_rights($emp->id,$type,$id); $rank=max($rank,$r['rank']); $rels=array_merge($rels,$r['relations']); }
        if($cap==='view' && $rank>=1) return true;
        if($cap==='view'){
            $sub=$wpdb->get_var($wpdb->prepare("SELECT ea.id FROM {$this->tables['entity_assignments']} ea JOIN {$this->tables['checklists']} c ON c.id=ea.entity_id WHERE ea.employee_id=%d AND ea.entity_type='subtask' AND c.task_id=%d LIMIT 1",$emp->id,$task->id));
            if($sub) return true;
        }
        if($cap==='edit' && $rank>=3) return true;
        if($cap==='complete' && $rank>=1 && (in_array('assignee',$rels,true)||in_array('owner',$rels,true))) return true;
        return false;
    }

    private function workspace_permission($workspace_id,$cap='view'){
        if($this->is_privileged_user()) return true;
        if($party=$this->current_party()){ if($cap!=='view') return false; global $wpdb; $pid=(int)$party->id; return (bool)$wpdb->get_var($wpdb->prepare("SELECT 1 FROM (SELECT workspace_id FROM {$this->tables['workspace_parties']} WHERE party_id=%d UNION SELECT wa.workspace_id FROM {$this->tables['work_area_parties']} wap JOIN {$this->tables['workspace_areas']} wa ON wa.work_area_id=wap.work_area_id WHERE wap.party_id=%d) z WHERE workspace_id=%d LIMIT 1",$pid,$pid,$workspace_id)); }
        $emp=$this->current_employee(); if(!$emp) return false;
        $ar=$this->employee_entity_rights($emp->id,'workspace',$workspace_id);
        if($cap==='view' && $ar['rank']>=1) return true;
        if(in_array($cap,['edit','add','delete'],true) && $ar['rank']>=3) return true;
        if($cap==='complete' && $ar['rank']>=1 && (in_array('assignee',$ar['relations'],true)||in_array('owner',$ar['relations'],true))) return true;
        return false;
    }
    private function normalize_area_action_rights($rights,$relation_type=''){
        $allowed=['add','edit','delete','view'];
        if(is_array($rights)) $raw=$rights;
        else{
            $value=strtolower(trim((string)$rights));
            if($value==='view_edit'){
                $raw=strtolower((string)$relation_type)==='owner'?['add','edit','delete','view']:['add','edit','view'];
            }elseif($value==='edit'){
                $raw=['edit','view'];
            }elseif($value==='view'){
                $raw=['view'];
            }else{
                $raw=preg_split('/[\s,|+]+/',$value,-1,PREG_SPLIT_NO_EMPTY);
            }
        }
        $out=[];
        foreach((array)$raw as $action){
            $action=strtolower(sanitize_key((string)$action));
            if(in_array($action,$allowed,true) && !in_array($action,$out,true)) $out[]=$action;
        }
        // A User who can change content must be able to open the Area as well.
        if(array_intersect($out,['add','edit','delete']) && !in_array('view',$out,true)) $out[]='view';
        $ordered=[]; foreach($allowed as $action) if(in_array($action,$out,true)) $ordered[]=$action;
        return $ordered;
    }

    private function employee_area_action_rights($employee_id,$entity_type,$entity_id){
        global $wpdb;
        if(!in_array($entity_type,['note_area','math_area','work_area'],true)) return [];
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT rights,relation_type FROM {$this->tables['entity_assignments']} WHERE employee_id=%d AND entity_type=%s AND entity_id=%d AND archived_at IS NULL",
            $employee_id,$entity_type,$entity_id
        ));
        $actions=[];
        foreach((array)$rows as $row){
            $rights=(string)($row->rights??'');
            // Legacy direct area rows used Owner/Assignee semantics. Preserve their former access.
            if($rights==='view_edit') $parsed=['add','edit','delete','view'];
            elseif($rights==='view' && strtolower((string)($row->relation_type??''))==='owner') $parsed=['add','edit','delete','view'];
            else $parsed=$this->normalize_area_action_rights($rights,(string)($row->relation_type??''));
            foreach($parsed as $action) if(!in_array($action,$actions,true)) $actions[]=$action;
        }
        return $actions;
    }

    private function migrate_v41570_area_sharing_permissions(){
        global $wpdb;
        if(get_option('bb_v41570_area_sharing_migrated')) return;
        $now=current_time('mysql');
        foreach([
            ['space_type'=>'notespace','area_type'=>'note_area','area_table'=>$this->tables['note_areas'],'fk'=>'notespace_id'],
            ['space_type'=>'mathspace','area_type'=>'math_area','area_table'=>$this->tables['math_areas'],'fk'=>'mathspace_id'],
        ] as $cfg){
            $space_rows=$wpdb->get_results($wpdb->prepare(
                "SELECT entity_id,employee_id,relation_type,rights FROM {$this->tables['entity_assignments']} WHERE entity_type=%s AND archived_at IS NULL",
                $cfg['space_type']
            ));
            foreach((array)$space_rows as $row){
                $area_ids=array_map('intval',$wpdb->get_col($wpdb->prepare(
                    "SELECT id FROM {$cfg['area_table']} WHERE {$cfg['fk']}=%d",
                    (int)$row->entity_id
                )));
                $legacy_rights=strtolower((string)$row->relation_type)==='owner'?['add','edit','delete','view']:['add','edit','view'];
                $rights=implode(',',$legacy_rights);
                foreach($area_ids as $aid){
                    $exists=(int)$wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$this->tables['entity_assignments']} WHERE entity_type=%s AND entity_id=%d AND employee_id=%d AND archived_at IS NULL",
                        $cfg['area_type'],$aid,(int)$row->employee_id
                    ));
                    if($exists) continue;
                    $wpdb->insert($this->tables['entity_assignments'],[
                        'entity_type'=>$cfg['area_type'],'entity_id'=>$aid,'employee_id'=>(int)$row->employee_id,
                        'relation_type'=>'assignee','rights'=>$rights,'updated_at'=>$now
                    ]);
                }
            }
            // Top-level sharing is intentionally retired; access is now Area-specific.
            $wpdb->delete($this->tables['entity_assignments'],['entity_type'=>$cfg['space_type']]);
        }
        // Convert legacy direct Area Owner/Assignee rows into the new four-action rights.
        foreach(['note_area','math_area'] as $type){
            $rows=$wpdb->get_results($wpdb->prepare(
                "SELECT id,relation_type,rights FROM {$this->tables['entity_assignments']} WHERE entity_type=%s AND archived_at IS NULL",
                $type
            ));
            foreach((array)$rows as $row){
                $rights=(string)$row->rights;
                if(strpos($rights,',')!==false) continue;
                if(strtolower((string)$row->relation_type)==='owner') $actions=['add','edit','delete','view'];
                elseif($rights==='view_edit') $actions=['add','edit','delete','view'];
                else $actions=['add','edit','view'];
                $wpdb->update($this->tables['entity_assignments'],['relation_type'=>'assignee','rights'=>implode(',',$actions),'updated_at'=>$now],['id'=>(int)$row->id]);
            }
        }
        update_option('bb_v41570_area_sharing_migrated',1);
    }

    private function notespace_permission($notespace_id,$cap='view'){
        if($this->is_privileged_user()) return true;
        $emp=$this->current_employee(); if(!$emp || !$notespace_id) return false;
        $ar=$this->employee_entity_rights($emp->id,'notespace',$notespace_id);
        if($cap==='view' && $ar['rank']>=1) return true;
        if(in_array($cap,['edit','add','delete','share'],true) && $ar['rank']>=3 && in_array('owner',$ar['relations'],true)) return true;
        if(in_array($cap,['update','complete'],true) && $ar['rank']>=1 && (in_array('assignee',$ar['relations'],true)||in_array('owner',$ar['relations'],true))) return true;
        return false;
    }
    private function allowed_notespace_ids(){
        if($this->is_privileged_user()) return null;
        global $wpdb; $emp=$this->current_employee(); if(!$emp) return [];
        return array_values(array_unique(array_map('intval',$wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT entity_id FROM {$this->tables['entity_assignments']} WHERE employee_id=%d AND entity_type='notespace' AND archived_at IS NULL",
            $emp->id
        )))));
    }

    private function note_area_permission($note_area_id,$cap='view'){
        if($this->is_privileged_user()) return true;
        global $wpdb; $emp=$this->current_employee(); if(!$emp || !$note_area_id) return false;
        $area=$wpdb->get_row($wpdb->prepare("SELECT notespace_id FROM {$this->tables['note_areas']} WHERE id=%d",$note_area_id));
        if(!$area) return false;

        // A Notespace share is inherited by all Note Areas inside it.
        if($this->notespace_permission((int)$area->notespace_id,$cap)) return true;

        // Area-level sharing remains granular: ADD / EDIT / DELETE / VIEW.
        $actions=$this->employee_area_action_rights((int)$emp->id,'note_area',(int)$note_area_id);
        $map=[
            'view'=>'view','add'=>'add','create'=>'add','edit'=>'edit','update'=>'edit',
            'delete'=>'delete','reorder'=>'edit','move'=>'edit'
        ];
        $required=$map[$cap]??'';
        return $required!=='' && in_array($required,$actions,true);
    }

    private function allowed_note_area_ids(){
        if($this->is_privileged_user()) return null;
        global $wpdb; $emp=$this->current_employee(); if(!$emp) return [];
        $areas=$wpdb->get_results("SELECT id,notespace_id FROM {$this->tables['note_areas']} ORDER BY notespace_id,sort_order,id");
        $ids=[];
        foreach((array)$areas as $area){
            $aid=(int)$area->id; $nid=(int)$area->notespace_id;
            $space_mine=(int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->tables['entity_assignments']} WHERE entity_type='notespace' AND entity_id=%d AND employee_id=%d AND archived_at IS NULL",
                $nid,$emp->id
            ));
            if($space_mine>0){ $ids[]=$aid; continue; }
            $area_mine=(int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->tables['entity_assignments']} WHERE entity_type='note_area' AND entity_id=%d AND employee_id=%d AND archived_at IS NULL",
                $aid,$emp->id
            ));
            if($area_mine>0) $ids[]=$aid;
        }
        return array_values(array_unique($ids));
    }

    private function mathspace_permission($mathspace_id,$cap='view'){
        if($this->is_privileged_user()) return true;
        $emp=$this->current_employee(); if(!$emp || !$mathspace_id) return false;
        $ar=$this->employee_entity_rights($emp->id,'mathspace',$mathspace_id);
        if($cap==='view' && $ar['rank']>=1) return true;
        if(in_array($cap,['edit','add','delete','share'],true) && $ar['rank']>=3 && in_array('owner',$ar['relations'],true)) return true;
        if(in_array($cap,['update','complete'],true) && $ar['rank']>=1 && (in_array('assignee',$ar['relations'],true)||in_array('owner',$ar['relations'],true))) return true;
        return false;
    }

    private function allowed_mathspace_ids(){
        if($this->is_privileged_user()) return null;
        global $wpdb; $emp=$this->current_employee(); if(!$emp) return [];
        return array_values(array_unique(array_map('intval',$wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT entity_id FROM {$this->tables['entity_assignments']} WHERE employee_id=%d AND entity_type='mathspace' AND archived_at IS NULL",
            $emp->id
        )))));
    }

    private function math_area_permission($math_area_id,$cap='view'){
        if($this->is_privileged_user()) return true;
        global $wpdb; $emp=$this->current_employee(); if(!$emp || !$math_area_id) return false;
        $area=$wpdb->get_row($wpdb->prepare("SELECT mathspace_id FROM {$this->tables['math_areas']} WHERE id=%d",$math_area_id));
        if(!$area) return false;

        // A MathSpace share is inherited by all Math Areas inside it.
        if($this->mathspace_permission((int)$area->mathspace_id,$cap)) return true;

        // Area-level sharing remains granular: ADD / EDIT / DELETE / VIEW.
        $actions=$this->employee_area_action_rights((int)$emp->id,'math_area',(int)$math_area_id);
        $map=[
            'view'=>'view','add'=>'add','create'=>'add','edit'=>'edit','update'=>'edit',
            'delete'=>'delete','reorder'=>'edit','move'=>'edit'
        ];
        $required=$map[$cap]??'';
        return $required!=='' && in_array($required,$actions,true);
    }
    private function allowed_math_area_ids(){
        if($this->is_privileged_user()) return null;
        global $wpdb; $emp=$this->current_employee(); if(!$emp) return [];
        $areas=$wpdb->get_results("SELECT id,mathspace_id FROM {$this->tables['math_areas']} ORDER BY mathspace_id,sort_order,id");
        $ids=[];
        foreach((array)$areas as $area){
            $aid=(int)$area->id; $mid=(int)$area->mathspace_id;
            $space_mine=(int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->tables['entity_assignments']} WHERE entity_type='mathspace' AND entity_id=%d AND employee_id=%d AND archived_at IS NULL",
                $mid,$emp->id
            ));
            if($space_mine>0){ $ids[]=$aid; continue; }
            $area_mine=(int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->tables['entity_assignments']} WHERE entity_type='math_area' AND entity_id=%d AND employee_id=%d AND archived_at IS NULL",
                $aid,$emp->id
            ));
            if($area_mine>0) $ids[]=$aid;
        }
        return array_values(array_unique($ids));
    }

    private function work_area_permission($work_area_id,$cap='view'){
        if($this->is_privileged_user()) return true;
        if($party=$this->current_party()){ return $this->party_area_can((int)$party->id,$work_area_id,$cap); }
        $emp=$this->current_employee(); if(!$emp || !$work_area_id) return false;
        $actions=$this->employee_area_action_rights((int)$emp->id,'work_area',(int)$work_area_id);
        if($cap==='view' && in_array('view',$actions,true)) return true;
        if($cap==='add' && in_array('add',$actions,true)) return true;
        if($cap==='edit' && in_array('edit',$actions,true)) return true;
        if($cap==='delete' && in_array('delete',$actions,true)) return true;
        if($cap==='complete' && in_array('edit',$actions,true)) return true;
        // Legacy fallback for old records that have not yet been re-saved through the new UI.
        $ar=$this->employee_entity_rights($emp->id,'work_area',$work_area_id);
        if($cap==='view' && $ar['rank']>=1) return true;
        if(in_array($cap,['edit','add','delete'],true) && $ar['rank']>=3 && in_array('owner',$ar['relations'],true)) return true;
        if($cap==='complete' && $ar['rank']>=1 && (in_array('assignee',$ar['relations'],true)||in_array('owner',$ar['relations'],true))) return true;
        return false;
    }
    private function allowed_workspace_ids(){
        if($this->is_privileged_user()) return null;
        global $wpdb;
        if($party=$this->current_party()){ $pid=(int)$party->id; return array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT DISTINCT workspace_id FROM (SELECT workspace_id FROM {$this->tables['workspace_parties']} WHERE party_id=%d UNION SELECT wa.workspace_id FROM {$this->tables['work_area_parties']} wap JOIN {$this->tables['workspace_areas']} wa ON wa.work_area_id=wap.work_area_id WHERE wap.party_id=%d) z",$pid,$pid))); }
        $emp=$this->current_employee(); if(!$emp) return [];
        $ids=[];
        $direct=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT entity_id FROM {$this->tables['entity_assignments']} WHERE employee_id=%d AND entity_type='workspace'",$emp->id)); foreach($direct as $x)$ids[]=(int)$x;
        $wa=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT wa.workspace_id FROM {$this->tables['entity_assignments']} ea JOIN {$this->tables['workspace_areas']} wa ON wa.work_area_id=ea.entity_id WHERE ea.employee_id=%d AND ea.entity_type='work_area'",$emp->id)); foreach($wa as $x)$ids[]=(int)$x;
        $ta=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT t.workspace_id FROM {$this->tables['entity_assignments']} ea JOIN {$this->tables['tasks']} t ON t.id=ea.entity_id WHERE ea.employee_id=%d AND ea.entity_type='task'",$emp->id)); foreach($ta as $x)$ids[]=(int)$x;
        $sa=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT t.workspace_id FROM {$this->tables['entity_assignments']} ea JOIN {$this->tables['checklists']} c ON c.id=ea.entity_id JOIN {$this->tables['tasks']} t ON t.id=c.task_id WHERE ea.employee_id=%d AND ea.entity_type='subtask'",$emp->id)); foreach($sa as $x)$ids[]=(int)$x;
        return array_values(array_unique($ids));
    }
    private function log_activity($action,$details='',$workspace_id=0,$work_area_id=0,$task_id=0,$occurrence_date=null){
        global $wpdb; $emp=$this->current_employee();
        $wpdb->insert($this->tables['activity'],['user_id'=>get_current_user_id()?:null,'employee_id'=>$emp?$emp->id:null,'workspace_id'=>$workspace_id?:null,'work_area_id'=>$work_area_id?:null,'task_id'=>$task_id?:null,'occurrence_date'=>$occurrence_date?:null,'action'=>sanitize_key($action),'details'=>sanitize_text_field($details),'created_at'=>current_time('mysql')]);
    }

    // Activity rows are written using WordPress' configured local timezone.
    // Normalize them to Asia/Kolkata before they reach Workboard so Activity Log,
    // task activity and Excel all show the same Indian date/time.
    private function activity_time_ist($value){
        $value=trim((string)$value);
        if($value==='') return '';
        try{
            $source=function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
            $dt=DateTimeImmutable::createFromFormat('Y-m-d H:i:s',$value,$source);
            if(!$dt) $dt=new DateTimeImmutable($value,$source);
            return $dt->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('Y-m-d H:i:s');
        }catch(Throwable $e){
            return $value;
        }
    }
    private function activity_rows_ist($rows){
        if(!is_array($rows)) return $rows;
        foreach($rows as $row){
            if(is_object($row) && isset($row->created_at)) $row->created_at=$this->activity_time_ist($row->created_at);
            elseif(is_array($row) && isset($row['created_at'])) $row['created_at']=$this->activity_time_ist($row['created_at']);
        }
        return $rows;
    }
    // v4.16.80: targeted indexes for the operational Pending Tasks path.
    // These are added once without rerunning the full dbDelta schema migration.
    private function ensure_pending_performance_indexes(){
        global $wpdb;
        if((string)get_option('bb_pending_perf_indexes_v1','')==='1') return;
        $indexes=[
            [$this->tables['tasks'],'bb_pending_scope','is_template,archived_at,status,workspace_id,due_date'],
            [$this->tables['history'],'bb_pending_history','task_id,status,occurrence_date'],
        ];
        foreach($indexes as [$table,$name,$cols]){
            $exists=$wpdb->get_var($wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name=%s LIMIT 1",$name));
            if(!$exists) $wpdb->query("ALTER TABLE {$table} ADD KEY {$name} ({$cols})");
        }
        update_option('bb_pending_perf_indexes_v1','1',false);
    }

    private function is_exception($task_id,$date){ global $wpdb; return (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['recurrence_exceptions']} WHERE task_id=%d AND occurrence_date=%s LIMIT 1",$task_id,$date)); }

    /**
     * Rebuild site-scoped table names after switch_to_blog().
     * Network tables intentionally keep the network base prefix.
     */
    private function refresh_site_tables(){
        global $wpdb;
        // Keep this list in sync with the constructor. Ajax/cron code can call
        // refresh_site_tables() after switch_to_blog(); omitting a key here makes the
        // corresponding table name blank for the rest of that request.
        $this->tables = [
            'workspaces' => $wpdb->prefix.'bb_workspaces',
            'folders' => $wpdb->prefix.'bb_folders',
            'folder_workspaces' => $wpdb->prefix.'bb_folder_workspaces',
            'notespaces' => $wpdb->prefix.'bb_notespaces',
            'folder_notespaces' => $wpdb->prefix.'bb_folder_notespaces',
            'note_areas' => $wpdb->prefix.'bb_note_areas',
            'note_cards' => $wpdb->prefix.'bb_note_cards',
            'mathspaces' => $wpdb->prefix.'bb_mathspaces',
            'math_areas' => $wpdb->prefix.'bb_math_areas',
            'math_entries' => $wpdb->prefix.'bb_math_entries',
            'work_areas' => $wpdb->prefix.'bb_work_areas',
            'stage_master' => $wpdb->prefix.'bb_stage_master',
            'labels' => $wpdb->prefix.'bb_labels',
            'workspace_areas' => $wpdb->prefix.'bb_workspace_areas',
            'employees' => $wpdb->prefix.'bb_employees',
            'parties' => $wpdb->prefix.'bb_parties',
            'work_area_parties' => $wpdb->prefix.'bb_work_area_parties',
            'workspace_parties' => $wpdb->prefix.'bb_workspace_parties',
            'attendance_combos' => $wpdb->prefix.'bb_attendance_combos',
            'attendance_events' => $wpdb->prefix.'bb_attendance_events',
            'attendance_audit' => $wpdb->prefix.'bb_attendance_audit',
            'attendance_holidays' => $wpdb->prefix.'bb_attendance_holidays',
            'attendance_weekly_off_claims' => $wpdb->prefix.'bb_attendance_weekly_off_claims',
            'attendance_leaves' => $wpdb->prefix.'bb_attendance_leaves',
            'attendance_payroll_adjustments' => $wpdb->prefix.'bb_attendance_payroll_adjustments',
            'designations' => $wpdb->prefix.'bb_designations',
            'tasks' => $wpdb->prefix.'bb_tasks',
            'checklists' => $wpdb->prefix.'bb_checklists',
            'subtask_progress' => $wpdb->prefix.'bb_subtask_progress',
            'history' => $wpdb->prefix.'bb_task_history',
            'score_archive' => $wpdb->prefix.'bb_score_archive',
            'workspace_templates' => $wpdb->prefix.'bb_workspace_templates',
            'work_area_templates' => $wpdb->prefix.'bb_work_area_templates',
            'workspace_access' => $wpdb->prefix.'bb_workspace_access',
            'activity' => $wpdb->prefix.'bb_activity_log',
            'recurrence_exceptions' => $wpdb->prefix.'bb_recurrence_exceptions',
            'notifications' => $wpdb->prefix.'bb_notifications',
            'attachments' => $wpdb->prefix.'bb_task_attachments',
            'template_versions' => $wpdb->prefix.'bb_template_versions',
            'departments' => $wpdb->prefix.'bb_org_departments',
            'employee_departments' => $wpdb->prefix.'bb_employee_departments',
            'workspace_departments' => $wpdb->prefix.'bb_workspace_departments',
            'entity_assignments' => $wpdb->prefix.'bb_entity_assignments',
            'task_remarks' => $wpdb->prefix.'bb_task_remarks',
            'goal_progress' => $wpdb->prefix.'bb_task_goal_progress',
            'task_reminders' => $wpdb->prefix.'bb_task_reminders',
            'task_overdue_reminders' => $wpdb->prefix.'bb_task_overdue_reminders',
            'reminder_delivery' => $wpdb->prefix.'bb_reminder_delivery',
        ];
    }


    private function notify_employee($employee_id,$type,$title,$message,$source_key,$workspace_id=0,$task_id=0,$occurrence_date=null){
        global $wpdb;
        $this->last_whatsapp_result=null;
        $employee_id=absint($employee_id); if(!$employee_id || !$source_key) return false;
        // Task Updated messages are strictly opt-in per task and recipient.
        // Workspace/Work Area sharing, heads and assignees never confer eligibility.
        if($this->whatsapp_normalize_type($type)==='task_update'){
            $task_id=absint($task_id);
            if(!$task_id) return false;
            $task=$wpdb->get_row($wpdb->prepare("SELECT id,notify_enabled FROM {$this->tables['tasks']} WHERE id=%d",$task_id));
            if(!$task || !$this->task_notifications_enabled($task) || !in_array($employee_id,$this->task_owner_employee_ids($task),true)) return false;
        }
        $emp=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['employees']} WHERE id=%d",$employee_id));
        if(!$emp) return false;
        $data=[
            'employee_id'=>$employee_id,'wp_user_id'=>$emp->wp_user_id?:null,'workspace_id'=>$workspace_id?:null,'task_id'=>$task_id?:null,
            'occurrence_date'=>$occurrence_date?:null,'type'=>sanitize_key($type),'title'=>sanitize_text_field($title),
            'message'=>sanitize_text_field($message),'source_key'=>sanitize_text_field($source_key),'is_read'=>0,'created_at'=>current_time('mysql')
        ];
        $ok=$wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$this->tables['notifications']} (employee_id,wp_user_id,workspace_id,task_id,occurrence_date,type,title,message,source_key,is_read,created_at)
             VALUES (%d,%s,%s,%s,%s,%s,%s,%s,%s,0,%s)",
            $data['employee_id'],$data['wp_user_id'],$data['workspace_id'],$data['task_id'],$data['occurrence_date'],$data['type'],$data['title'],$data['message'],$data['source_key'],$data['created_at']
        ));
        if($ok && get_option('bb_notify_email',0) && !empty($emp->email)){
            wp_mail($emp->email,'Workboard: '.$title,$message);
        }
        if($ok && get_option('bb_meta_whatsapp_enabled',0) && !empty($emp->mobile)){
            // Preserve the internal Workboard notification, but also retain the actual
            // WhatsApp API result so scheduled/test senders can distinguish a DB insert
            // from a message that Meta really accepted.
            $this->last_whatsapp_result=$this->send_meta_whatsapp($emp->mobile,$title,$message,$type,false,$task_id,$occurrence_date,$employee_id,$source_key);
        }
        return (bool)$ok;
    }


    private function whatsapp_normalize_number($raw,$country_code=null){
        $digits=preg_replace('/\D+/','',(string)$raw);
        if(!$digits) return '';
        $cc=preg_replace('/\D+/','',(string)($country_code!==null?$country_code:get_option('bb_meta_whatsapp_country_code','91')));
        if(strlen($digits)===10 && $cc) $digits=$cc.$digits;
        return $digits;
    }

    private function whatsapp_normalize_type($type){
        $type=sanitize_key((string)$type);
        $aliases=[
            // Task update aliases. Several task update paths historically used a
            // status/action name instead of the canonical `task_update` event.
            // Without normalization those events fell through to the generic
            // two-parameter payload (Task + Due Date), while the selected Meta
            // template was still Task Updated. That shifted Due Date into the
            // Workspace variable and padded the remaining values with "-".
            'task_updated'=>'task_update',
            'tasks_updated'=>'task_update',
            'update'=>'task_update',
            'task_completed'=>'task_update',
            'completed'=>'task_update',
            'task_reopened'=>'task_update',
            'reopened'=>'task_update',
            'task_stage_updated'=>'task_update',
            'task_stage_changed'=>'task_update',
            'stage_updated'=>'task_update',
            'task_assigned'=>'assignment',
            'assigned'=>'assignment',
            'task_due'=>'due_soon',
            'task_overdue'=>'overdue',
        ];
        return $aliases[$type]??$type;
    }

    private function whatsapp_template_name($type){
        $type=$this->whatsapp_normalize_type($type);
        $map=[
            'assignment'=>['option'=>'bb_meta_tpl_assignment','default'=>'workboard_task_assigned'],
            'task_update'=>['option'=>'bb_meta_tpl_update','default'=>'workboard_task_updated'],
            'due_soon'=>['option'=>'bb_meta_tpl_due','default'=>'workboard_task_due'],
            'overdue'=>['option'=>'bb_meta_tpl_overdue','default'=>'workboard_task_overdue'],
            'daily_summary'=>['option'=>'bb_meta_tpl_daily','default'=>'workboard_pending_task_summary'],
            'morning_pending'=>['option'=>'bb_meta_tpl_morning','default'=>'workboard_pending_morningreminder'],
            'urgent_update'=>['option'=>'bb_meta_tpl_urgent','default'=>'workboard_urgent_reminder'],
            'after_due_reminder'=>['option'=>'bb_meta_tpl_due_reminder','default'=>'workboard_due_reminder'],
            'task_reminder'=>['option'=>'bb_meta_tpl_reminder','default'=>'workboard_task_reminder'],
            'attendance_summary'=>['option'=>'bb_attendance_whatsapp_template','default'=>'workboard_daily_attendance_summary'],
        ];
        if(!isset($map[$type])) return '';
        $cfg=$map[$type];
        return sanitize_key((string)get_option($cfg['option'],$cfg['default']));
    }

    private function whatsapp_event_enabled($type){
        $type=$this->whatsapp_normalize_type($type);
        $map=[
            'assignment'=>'bb_meta_tpl_assignment_enabled',
            'task_update'=>'bb_meta_tpl_update_enabled',
            'due_soon'=>'bb_meta_tpl_due_enabled',
            'overdue'=>'bb_meta_tpl_overdue_enabled',
            'daily_summary'=>'bb_meta_tpl_daily_enabled',
            'morning_pending'=>'bb_meta_tpl_morning_enabled',
            'urgent_update'=>'bb_meta_tpl_urgent_enabled',
            'after_due_reminder'=>'bb_meta_tpl_due_reminder_enabled',
            'task_reminder'=>'bb_meta_tpl_reminder_enabled',
            'attendance_summary'=>'bb_attendance_whatsapp_active',
        ];
        if(!isset($map[$type])) return false;
        $option=$map[$type];
        return (int)get_option($option,$type==='task_reminder'?0:1)===1;
    }

    private function whatsapp_template_parameters($type,$title,$message,$task_id=0,$occurrence_date=null,$force_test=false,$recipient_employee_id=0){
        global $wpdb;
        $type=$this->whatsapp_normalize_type($type);
        if($force_test){
            if($type==='attendance_summary') return [$this->attendance_ist_now('d/m/Y'),'Test Summary'];
            if($type==='morning_pending') return ['Manash',"1. Prepare September Sales Report - (29/09/2026) | 2. Follow up client - (30/09/2026)"];
            if($type==='daily_summary') return ['4','2'];
            if($type==='assignment') return ['Prepare September Sales Report','Sales Operations','September Follow-ups','Himanshu Kumar, Aman Jain','29/09/2026 12:50'];
            if($type==='task_update') return ['Do this do that','Sales Operations','September Follow-ups','26/09/2026','08/09/2026','Aman Jain','Please confirm with the client.'];
            if($type==='urgent_update') return ['Prepare September Sales Report','Sales Operations','September Follow-ups','Please complete this today.','Aman Jain'];
            if($type==='after_due_reminder') return ['Prepare September Sales Report','Sales Operations','September Follow-ups','30 Sep 2026 · 10:16 AM','Task is overdue and still not completed.'];
            if($type==='task_reminder') return ['Prepare September Sales Report','29/09/2026 12:50'];
            return ['Prepare September Sales Report','29/09/2026'];
        }
        if($type==='attendance_summary'){
            $label=trim((string)$message)?:'Attendance Summary';
            return [$this->attendance_ist_now('d/m/Y'),wp_strip_all_tags($label)];
        }
        if($type==='morning_pending'){
            // Meta template parameters cannot contain new-line/tab characters or
            // more than four consecutive spaces. Keep the approved two-variable
            // template and send the numbered task list as one safe text parameter.
            $employee_name='User';
            if($recipient_employee_id){
                $name=(string)$wpdb->get_var($wpdb->prepare("SELECT name FROM {$this->tables['employees']} WHERE id=%d",absint($recipient_employee_id)));
                $name=trim(wp_strip_all_tags($name));
                if($name!=='') $employee_name=$name;
            }
            $raw=wp_strip_all_tags((string)$message);
            $lines=preg_split('/\R/u',$raw);
            $clean=[];
            foreach((array)$lines as $line){
                $line=trim((string)$line);
                if($line==='') continue;
                $line=preg_replace('/[\t\r\n]+/u',' ',$line);
                $line=preg_replace('/ {2,}/u',' ',$line);
                $clean[]=$line;
            }
            $digest=trim(implode(' • ',$clean));
            return [wp_strip_all_tags($employee_name),$digest];
        }
        if($type==='daily_summary'){
            $due='0'; $over='0';
            if(preg_match('/Today:\s*(\d+)/i',(string)$message,$m)) $due=$m[1];
            if(preg_match('/(\d+)\s+overdue/i',(string)$message,$m)) $over=$m[1];
            return [$due,$over];
        }
        $task=null;
        if($task_id) $task=$wpdb->get_row($wpdb->prepare("SELECT t.task_name,t.task_type,t.due_date,t.due_time,t.occurrence_date,t.start_date,t.assignee_id,t.created_at,t.workspace_id,t.work_area_id,w.name workspace_name,wa.name work_area_name FROM {$this->tables['tasks']} t LEFT JOIN {$this->tables['workspaces']} w ON w.id=t.workspace_id LEFT JOIN {$this->tables['work_areas']} wa ON wa.id=t.work_area_id WHERE t.id=%d",absint($task_id)));
        $task_name=$task && !empty($task->task_name) ? (string)$task->task_name : (string)$title;
        $workspace_text=$task && !empty($task->workspace_name) ? trim((string)$task->workspace_name) : '-';
        $work_area_text=$task && !empty($task->work_area_name) ? trim((string)$task->work_area_name) : '-';
        // v4.13.64: resolve the operational due date before building WhatsApp variables.
        // One-time tasks use due_date. Recurring templates intentionally keep due_date blank,
        // so Task Assigned must fall back to occurrence_date/start_date instead of sending "-".
        $due_date='';
        if(!empty($occurrence_date)) $due_date=(string)$occurrence_date;
        elseif($task && !empty($task->due_date)) $due_date=(string)$task->due_date;
        elseif($task && !empty($task->occurrence_date)) $due_date=(string)$task->occurrence_date;
        elseif($task && !empty($task->start_date)) $due_date=(string)$task->start_date;
        $due_text=$due_date ? date_i18n('d/m/Y',strtotime($due_date)) : '-';
        if($type==='urgent_update'){
            // workboard_urgent_reminder variable order:
            // {{1}} Task, {{2}} Workspace, {{3}} Work Area, {{4}} Notes, {{5}} Required By.
            $urgent_notes=trim(wp_strip_all_tags((string)$message));
            $required_by='';
            $current_emp=$this->current_employee();
            if($current_emp && !empty($current_emp->name)) $required_by=trim(wp_strip_all_tags((string)$current_emp->name));
            if($required_by===''){
                $wp_user=wp_get_current_user();
                $display=trim(wp_strip_all_tags((string)($wp_user->display_name??'')));
                $login=trim(wp_strip_all_tags((string)($wp_user->user_login??'')));
                if($display!=='' && !is_email($display)) $required_by=$display;
                elseif($login!=='' && !is_email($login)) $required_by=$login;
                else $required_by='Workboard';
            }
            return [
                wp_strip_all_tags($task_name),
                wp_strip_all_tags($workspace_text!==''?$workspace_text:'-'),
                wp_strip_all_tags($work_area_text!==''?$work_area_text:'-'),
                $urgent_notes!==''?$urgent_notes:'-',
                wp_strip_all_tags($required_by)
            ];
        }
        if($type==='after_due_reminder'){
            // workboard_due_reminder variable order:
            // {{1}} Task, {{2}} Workspace, {{3}} Work Area, {{4}} Due Date, {{5}} Notes.
            $due_reminder_due='-';
            if($due_date){
                $due_reminder_due=date('d M Y',strtotime($due_date));
                $raw_due_time=$task ? trim((string)($task->due_time??'')) : '';
                // After-due scheduling uses 10:00 AM when no visible Due Time was selected.
                $display_due_time=$raw_due_time!=='' ? substr($raw_due_time,0,5) : '10:00';
                $due_stamp=strtotime($due_date.' '.$display_due_time);
                if($due_stamp) $due_reminder_due.=' · '.date('g:i A',$due_stamp);
            }
            return [
                wp_strip_all_tags($task_name),
                wp_strip_all_tags($workspace_text!==''?$workspace_text:'-'),
                wp_strip_all_tags($work_area_text!==''?$work_area_text:'-'),
                wp_strip_all_tags($due_reminder_due),
                'Task is overdue and still not completed.'
            ];
        }
        if($type==='task_reminder'){
            if($due_date && $task && !empty($task->due_time)){
                $time=preg_replace('/[^0-9:]/','',(string)$task->due_time);
                if($time) $due_text.=' '.substr($time,0,5);
            }
            return [wp_strip_all_tags($task_name),wp_strip_all_tags($due_text)];
        }
        if($type==='task_update'){
            // v4.13.68 Meta template variable order:
            // {{1}} Task, {{2}} Workspace, {{3}} Work Area, {{4}} Due Date,
            // {{5}} Update Date, {{6}} Assignee(s), {{7}} latest Remarks.
            $ist_now=new \DateTime('now',new \DateTimeZone('Asia/Kolkata'));
            $update_date=$ist_now->format('d/m/Y');
            $assignee_text=$this->task_assignee_names_text(absint($task_id),$task?absint($task->assignee_id):0);
            $remarks_text='-';
            if($task_id){
                $latest_remark=(string)$wpdb->get_var($wpdb->prepare("SELECT remark FROM {$this->tables['task_remarks']} WHERE task_id=%d ORDER BY id DESC LIMIT 1",absint($task_id)));
                $latest_remark=trim(wp_strip_all_tags($latest_remark));
                $latest_remark=preg_replace('/[\t\r\n]+/u',' ',$latest_remark);
                $latest_remark=preg_replace('/ {2,}/u',' ',$latest_remark);
                if($latest_remark!=='') $remarks_text=$latest_remark;
            }
            return [
                wp_strip_all_tags($task_name),
                wp_strip_all_tags($workspace_text),
                wp_strip_all_tags($work_area_text),
                wp_strip_all_tags($due_text),
                wp_strip_all_tags($update_date),
                wp_strip_all_tags($assignee_text),
                wp_strip_all_tags($remarks_text)
            ];
        }
        if($due_date && $task && !empty($task->due_time)){
            $time=preg_replace('/[^0-9:]/','',(string)$task->due_time);
            if($time) $due_text.=' '.substr($time,0,5);
        }
        if($type==='assignment'){
            // v4.13.68 Meta template variable order:
            // {{1}} Task, {{2}} Workspace, {{3}} Work Area, {{4}} Assigned To, {{5}} Due Date.
            // Multiple selected assignees remain comma-separated, e.g. "ABC, XYZ".
            $assigned_to=$this->task_assignee_names_text(absint($task_id),$task?absint($task->assignee_id):0);
            if($assigned_to==='-' && $recipient_employee_id){
                $assigned_to=(string)$wpdb->get_var($wpdb->prepare("SELECT name FROM {$this->tables['employees']} WHERE id=%d",absint($recipient_employee_id)));
                $assigned_to=trim($assigned_to) ?: 'Assignee';
            }
            return [
                wp_strip_all_tags($task_name),
                wp_strip_all_tags($workspace_text),
                wp_strip_all_tags($work_area_text),
                wp_strip_all_tags($assigned_to),
                wp_strip_all_tags($due_text)
            ];
        }
        return [wp_strip_all_tags($task_name),wp_strip_all_tags($due_text)];
    }

    private function send_meta_whatsapp($mobile,$title,$message,$type='task_update',$force_test=false,$task_id=0,$occurrence_date=null,$recipient_employee_id=0,$source_key='',$header_image_url=''){
        $type=$this->whatsapp_normalize_type($type);
        if(!get_option('bb_meta_whatsapp_enabled',0)) return ['ok'=>false,'message'=>'WhatsApp notifications are disabled'];
        if(!$force_test && !$this->whatsapp_event_enabled($type)) return ['ok'=>false,'message'=>'This WhatsApp notification type is disabled'];
        if(!$force_test && $type==='task_update'){
            global $wpdb;
            $task_id=absint($task_id);
            $task=$task_id?$wpdb->get_row($wpdb->prepare("SELECT id,notify_enabled FROM {$this->tables['tasks']} WHERE id=%d",$task_id)):null;
            if(!$task || !$this->task_notifications_enabled($task) || !absint($recipient_employee_id) || !in_array(absint($recipient_employee_id),$this->task_owner_employee_ids($task),true)){
                return ['ok'=>false,'message'=>'Task Updated WhatsApp is allowed only for explicitly selected Task Owners when Notify is enabled'];
            }
        }

        $creds=$this->resolved_messaging_credentials();
        $mode=$creds['mode']??'disabled';
        $template=$this->whatsapp_template_name($type);
        $phone_id=preg_replace('/\D+/','',(string)($creds['phone_id']??''));
        $token=trim((string)($creds['token']??''));
        $lang='en';
        $version=trim((string)($creds['api_version']??'v25.0')) ?: 'v25.0';
        if(!preg_match('/^v\d+\.\d+$/',$version)) $version='v25.0';
        $to=$this->whatsapp_normalize_number($mobile,$creds['country_code']??'91');
        $params=$this->whatsapp_template_parameters($type,$title,$message,$task_id,$occurrence_date,$force_test,$recipient_employee_id);

        // v4.16.170: Meta requires every approved body variable to be present.
        // Never send an empty/null template value: use "-" instead. Also guard
        // the Task Updated template against parameter-count mismatches by always
        // sending its approved seven body parameters, even if a caller/template
        // alias reaches this method with fewer values.
        $params=array_values((array)$params);
        foreach($params as $i=>$param){
            $clean=trim(wp_strip_all_tags((string)$param));
            $clean=preg_replace('/[\t\r\n]+/u',' ',$clean);
            $clean=preg_replace('/ {2,}/u',' ',$clean);
            $params[$i]=($clean==='')?'-':$clean;
        }
        $is_update_template=($type==='task_update') || (bool)preg_match('/(?:task|tasks)[_-]?updated|task[_-]?update/i',(string)$template);
        if($is_update_template){
            $params=array_slice($params,0,7);
            while(count($params)<7) $params[]='-';
        }

        $log_id=$this->message_log_create([
            'task_id'=>$task_id,'recipient_employee_id'=>$recipient_employee_id,'mobile'=>$to,'message_type'=>$type,
            'template_name'=>$template,'template_category'=>'utility','title'=>$title,'parameters'=>$params,
            'auth_mode'=>$mode,'trigger_type'=>$force_test?'test':(in_array($type,['due_soon','overdue','daily_summary','morning_pending','task_reminder','after_due_reminder'],true)?'scheduled':'event'),'source_key'=>$source_key
        ]);
        if(($creds['error']??'')!==''){
            $this->message_log_finish($log_id,'failed','',0,$creds['error'],0);
            return ['ok'=>false,'message'=>$creds['error'],'message_log_id'=>$log_id];
        }
        if(!$phone_id || !$token || !$template || !$to){
            $msg='Meta WhatsApp settings or recipient mobile are incomplete';
            $this->message_log_finish($log_id,'failed','',0,$msg,0);
            return ['ok'=>false,'message'=>$msg,'message_log_id'=>$log_id];
        }

        $charge=0.0;
        if($mode==='wallet'){
            $price=$this->site_selling_price(get_current_blog_id(),'utility');
            if($price===null){
                $msg='Wallet selling price is not configured by the Network Admin.';
                $this->message_log_finish($log_id,'failed','',0,$msg,0);
                return ['ok'=>false,'message'=>$msg,'message_log_id'=>$log_id];
            }
            $charge=(float)$price;
            if($charge>0 && $this->wallet_balance(get_current_blog_id())<$charge){
                $msg='Insufficient Workboard Wallet balance.';
                $this->message_log_finish($log_id,'failed','',0,$msg,0);
                return ['ok'=>false,'message'=>$msg,'message_log_id'=>$log_id];
            }
        }

        $body_params=[];
        foreach($params as $param) $body_params[]=['type'=>'text','text'=>(string)$param];
        $components=[];
        if($type==='attendance_summary' && $header_image_url!==''){
            $components[]=['type'=>'header','parameters'=>[['type'=>'image','image'=>['link'=>esc_url_raw($header_image_url)]]]];
        }
        $components[]=[
            'type'=>'body','parameters'=>$body_params,
        ];
        if($type==='morning_pending'){
            // Meta template button setup: URL = https://board.bizskilledu.com/{{1}}
            // v4.14.41: always point the button to the real Workspaces page.
            // Main site => workspaces/
            // Path-based subsite => vaa/workspaces/, nsc/workspaces/, etc.
            $path=trim((string)wp_parse_url(home_url('/'),PHP_URL_PATH),'/');
            $suffix=$path===''?'workspaces/':($path.'/workspaces/');
            $components[]=[
                'type'=>'button','sub_type'=>'url','index'=>'0',
                'parameters'=>[['type'=>'text','text'=>$suffix]],
            ];
        }
        $payload=[
            'messaging_product'=>'whatsapp','to'=>$to,'type'=>'template',
            'template'=>['name'=>$template,'language'=>['code'=>$lang],'components'=>$components],
        ];
        $url='https://graph.facebook.com/'.rawurlencode($version).'/'.rawurlencode($phone_id).'/messages';
        $res=wp_remote_post($url,['timeout'=>20,'headers'=>['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json'],'body'=>wp_json_encode($payload)]);
        if(is_wp_error($res)){
            $msg=$res->get_error_message();
            $this->message_log_finish($log_id,'failed','',0,$msg,0);
            return ['ok'=>false,'message'=>$msg,'message_log_id'=>$log_id];
        }
        $code=(int)wp_remote_retrieve_response_code($res);
        $body=json_decode((string)wp_remote_retrieve_body($res),true);
        if($code>=200 && $code<300){
            $meta_id=(string)($body['messages'][0]['id']??'');
            global $wpdb;
            $wpdb->update($this->network_tables['message_log'],[
                'status'=>'pending','meta_message_id'=>sanitize_text_field($meta_id),'http_code'=>$code,'error_message'=>'',
                'wallet_charge'=>0,'pending_wallet_charge'=>round((float)$charge,4),'updated_at'=>$this->network_utc_now_mysql()
            ],['id'=>absint($log_id)]);
            return ['ok'=>true,'message'=>'WhatsApp message accepted by Meta','response'=>$body,'message_log_id'=>$log_id,'meta_message_id'=>$meta_id];
        }
        $err=$body['error']['message']??('Meta API returned HTTP '.$code);
        // Include Meta's diagnostic detail (for example which parameter failed)
        // so the Settings > Test result is actionable instead of generic.
        $detail=(string)($body['error']['error_data']['details']??'');
        $subcode=(string)($body['error']['error_subcode']??'');
        if($detail!=='') $err.=' — '.$detail;
        if($subcode!=='') $err.=' (subcode '.$subcode.')';
        $err=sanitize_text_field($err);
        $this->message_log_finish($log_id,'failed','',$code,$err,0);
        return ['ok'=>false,'message'=>$err,'response'=>$body,'message_log_id'=>$log_id];
    }

    private function task_notifications_enabled($task){
        if(!$task) return false;
        return !isset($task->notify_enabled) || (int)$task->notify_enabled===1;
    }

    private function task_assignee_employee_ids($task_id,$fallback_assignee_id=0){
        global $wpdb;
        $ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT employee_id FROM {$this->tables['entity_assignments']} WHERE entity_type='task' AND entity_id=%d AND relation_type='assignee' AND archived_at IS NULL ORDER BY id ASC",$task_id)));
        if($fallback_assignee_id) $ids[]=(int)$fallback_assignee_id;
        return array_values(array_unique(array_filter($ids)));
    }

    private function task_assignee_names_text($task_id,$fallback_assignee_id=0){
        global $wpdb;
        $ids=$task_id?$this->task_assignee_employee_ids(absint($task_id),absint($fallback_assignee_id)):[];
        if(!$ids) return '-';
        $placeholders=implode(',',array_fill(0,count($ids),'%d'));
        $sql=$wpdb->prepare("SELECT id,name FROM {$this->tables['employees']} WHERE id IN ($placeholders)",$ids);
        $rows=$wpdb->get_results($sql);
        $name_by_id=[];
        foreach((array)$rows as $row){
            $name=trim((string)$row->name);
            if($name!=='') $name_by_id[(int)$row->id]=$name;
        }
        $names=[];
        foreach($ids as $id){ if(!empty($name_by_id[(int)$id])) $names[]=$name_by_id[(int)$id]; }
        $names=array_values(array_unique($names));
        return $names?implode(', ',$names):'-';
    }

    private function task_owner_employee_ids($task){
        global $wpdb;
        if(!$task) return [];
        // v4.11.67: task-update notifications go only to Owners explicitly selected on this task.
        // Do not inherit Workspace or Work Area Owners for this notification.
        $ids=$wpdb->get_col($wpdb->prepare("SELECT employee_id FROM {$this->tables['entity_assignments']} WHERE entity_type='task' AND entity_id=%d AND relation_type='owner' AND archived_at IS NULL ORDER BY id ASC",(int)$task->id));
        return array_values(array_unique(array_filter(array_map('intval',(array)$ids))));
    }

    private function work_area_update_remarks_required($work_area_id){
        global $wpdb;
        $work_area_id=absint($work_area_id);
        if(!$work_area_id) return false;
        return (int)$wpdb->get_var($wpdb->prepare("SELECT update_remarks_required FROM {$this->tables['work_areas']} WHERE id=%d",$work_area_id))===1;
    }

    private function save_task_workflow_remark($task_id,$occurrence_key,$remark){
        global $wpdb;
        $remark=trim(sanitize_textarea_field($remark));
        if($remark==='') return 0;
        $emp=$this->current_employee();
        $wpdb->insert($this->tables['task_remarks'],[
            'task_id'=>absint($task_id),
            'occurrence_key'=>$occurrence_key?:'one',
            'employee_id'=>$emp?(int)$emp->id:null,
            'remark'=>$remark,
            'created_at'=>current_time('mysql')
        ]);
        return (int)$wpdb->insert_id;
    }

    private function notify_task_assignment($task,$employee_ids){
        if(!$task || !$this->task_notifications_enabled($task) || !get_option('bb_notify_assignment',1)) return;
        $area='';
        global $wpdb;
        if(!empty($task->work_area_id)) $area=(string)$wpdb->get_var($wpdb->prepare("SELECT name FROM {$this->tables['work_areas']} WHERE id=%d",(int)$task->work_area_id));
        $assigned_due=!empty($task->due_date)?(string)$task->due_date:(!empty($task->occurrence_date)?(string)$task->occurrence_date:(!empty($task->start_date)?(string)$task->start_date:''));
        $due=$assigned_due?' Due '.date_i18n('d M Y',strtotime($assigned_due)).'.':'';
        $msg='You have been assigned: '.$task->task_name.($area?' ('.$area.')':'').'.'.$due;
        foreach(array_values(array_unique(array_map('intval',(array)$employee_ids))) as $eid){
            if(!$eid) continue;
            $this->notify_employee($eid,'assignment','Task assigned',$msg,'assign_'.$task->id.'_'.$eid.'_'.wp_generate_uuid4(),$task->workspace_id,$task->id,$task->due_date?:null);
        }
    }

    private function notify_task_owners($task,$change='Task updated',$details=''){
        if(!$task || !$this->task_notifications_enabled($task)) return;
        $actor=$this->current_employee();
        $who=$actor?trim((string)$actor->name):'A user';
        $msg=$who.' updated task: '.$task->task_name.'.'.($details?' '.$details:'');
        foreach($this->task_owner_employee_ids($task) as $eid){
            if(!$eid) continue;
            $this->notify_employee($eid,'task_update',$change,$msg,'upd_'.$task->id.'_'.$eid.'_'.wp_generate_uuid4(),$task->workspace_id,$task->id,null);
        }
    }

    private function notify_work_area_head($work_area_id,$type,$title,$message,$source_key,$workspace_id=0,$task_id=0,$occurrence_date=null,$exclude_employee_id=0){
        global $wpdb;
        $head=(int)$wpdb->get_var($wpdb->prepare("SELECT head_employee_id FROM {$this->tables['work_areas']} WHERE id=%d",$work_area_id));
        if($head && $head!==absint($exclude_employee_id)) $this->notify_employee($head,$type,$title,$message,$source_key.'_head',$workspace_id,$task_id,$occurrence_date);
    }

    private function ensure_note_area_date_columns(){
        global $wpdb;
        $table=$this->tables['note_areas'];
        if(!$table) return;
        $has_applicable=$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s",'date_applicable'));
        if(!$has_applicable) $wpdb->query("ALTER TABLE {$table} ADD COLUMN date_applicable tinyint(1) NOT NULL DEFAULT 0 AFTER color");
        $has_date=$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s",'applicable_date'));
        if(!$has_date) $wpdb->query("ALTER TABLE {$table} ADD COLUMN applicable_date date DEFAULT NULL AFTER date_applicable");
    }

    private function ensure_note_card_date_column(){
        global $wpdb;
        $table=$this->tables['note_cards'];
        if(!$table) return;
        $has_date=$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s",'note_date'));
        if(!$has_date) $wpdb->query("ALTER TABLE {$table} ADD COLUMN note_date date DEFAULT NULL AFTER thumbnail_url");
    }

    private function ensure_work_area_update_remarks_column(){
        global $wpdb;
        $table=$this->tables['work_areas'];
        if(!$table) return;
        $has=$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s",'update_remarks_required'));
        if(!$has) $wpdb->query("ALTER TABLE {$table} ADD COLUMN update_remarks_required tinyint(1) NOT NULL DEFAULT 0 AFTER stages_json");
        $has_limit=$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s",'remarks_view_days_limit'));
        if(!$has_limit) $wpdb->query("ALTER TABLE {$table} ADD COLUMN remarks_view_days_limit int unsigned NOT NULL DEFAULT 0 AFTER update_remarks_required");
    }

    private function ensure_task_reminders_table(){
        global $wpdb;
        $table=$this->tables['task_reminders'];
        $exists=$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$table));
        if($exists===$table) return;
        $charset=$wpdb->get_charset_collate();
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            task_id bigint(20) unsigned NOT NULL,
            reminder_value int NOT NULL DEFAULT 1,
            reminder_unit varchar(20) NOT NULL DEFAULT 'Hours',
            sort_order int NOT NULL DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY task_id (task_id),
            KEY task_sort (task_id,sort_order)
        ) {$charset}");
    }

    private function ensure_task_overdue_reminders_table(){
        global $wpdb;
        $table=$this->tables['task_overdue_reminders'];
        $charset=$wpdb->get_charset_collate();
        // Keep this as a targeted table migration so reminder feature releases do not rerun
        // the full Workboard schema upgrade on the first frontend request.
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            task_id bigint(20) unsigned NOT NULL,
            enabled tinyint(1) NOT NULL DEFAULT 0,
            interval_value int NOT NULL DEFAULT 1,
            interval_unit varchar(20) NOT NULL DEFAULT 'Hours',
            active_start time DEFAULT '08:00:00',
            active_end time DEFAULT '20:00:00',
            all_day tinyint(1) NOT NULL DEFAULT 0,
            duration_days int NOT NULL DEFAULT 0,
            max_reminders int NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY task_id (task_id),
            KEY enabled (enabled)
        ) {$charset}");
    }

    private function task_overdue_reminder_settings($task_id){
        global $wpdb;
        $this->ensure_task_overdue_reminders_table();
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->tables['task_overdue_reminders']} WHERE task_id=%d LIMIT 1",
            absint($task_id)
        ));
        if($row) return $row;
        return (object)[
            'task_id'=>absint($task_id),'enabled'=>0,'interval_value'=>1,'interval_unit'=>'Hours',
            'active_start'=>'08:00:00','active_end'=>'20:00:00','all_day'=>0,'duration_days'=>0,'max_reminders'=>0
        ];
    }

    private function normalize_overdue_reminder_settings($raw,$overall_enabled=true){
        $raw=is_array($raw)?$raw:[];
        $unit=sanitize_text_field($raw['interval_unit']??'Hours');
        if(!in_array($unit,['Minutes','Hours','Days','Weeks'],true)) $unit='Hours';
        $start=substr(sanitize_text_field($raw['active_start']??'08:00'),0,5);
        $end=substr(sanitize_text_field($raw['active_end']??'20:00'),0,5);
        if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$start)) $start='08:00';
        if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$end)) $end='20:00';
        return [
            'enabled'=>($overall_enabled && !empty($raw['enabled']))?1:0,
            'interval_value'=>max(1,min(9999,absint($raw['interval_value']??1))),
            'interval_unit'=>$unit,
            'active_start'=>$start.':00',
            'active_end'=>$end.':00',
            'all_day'=>!empty($raw['all_day'])?1:0,
            // Zero means there is no artificial stop: reminders continue until the task is completed.
            'duration_days'=>max(0,min(3651,absint($raw['duration_days']??0))),
            'max_reminders'=>max(0,min(10000,absint($raw['max_reminders']??0))),
        ];
    }

    private function sync_task_overdue_reminder_settings($task_id,$settings){
        global $wpdb;
        $this->ensure_task_overdue_reminders_table();
        $task_id=absint($task_id);
        $cfg=$this->normalize_overdue_reminder_settings($settings,true);
        $now=current_time('mysql');
        $exists=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['task_overdue_reminders']} WHERE task_id=%d LIMIT 1",$task_id));
        $data=[
            'enabled'=>$cfg['enabled'],'interval_value'=>$cfg['interval_value'],'interval_unit'=>$cfg['interval_unit'],
            'active_start'=>$cfg['active_start'],'active_end'=>$cfg['active_end'],'all_day'=>$cfg['all_day'],
            'duration_days'=>$cfg['duration_days'],'max_reminders'=>$cfg['max_reminders'],'updated_at'=>$now
        ];
        if($exists) $wpdb->update($this->tables['task_overdue_reminders'],$data,['task_id'=>$task_id]);
        else { $data['task_id']=$task_id; $data['created_at']=$now; $wpdb->insert($this->tables['task_overdue_reminders'],$data); }
    }

    private function disable_task_overdue_reminders($task_id){
        global $wpdb;
        $this->ensure_task_overdue_reminders_table();
        $wpdb->update($this->tables['task_overdue_reminders'],['enabled'=>0,'updated_at'=>current_time('mysql')],['task_id'=>absint($task_id)]);
    }

    private function ensure_reminder_delivery_table(){
        global $wpdb;
        $table=$this->tables['reminder_delivery'];
        $exists=$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$table));
        if($exists===$table) return;
        $charset=$wpdb->get_charset_collate();
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_key varchar(190) NOT NULL,
            task_id bigint(20) unsigned NOT NULL,
            occurrence_date date DEFAULT NULL,
            employee_id bigint(20) unsigned NOT NULL,
            reminder_value int NOT NULL DEFAULT 1,
            reminder_unit varchar(20) NOT NULL DEFAULT 'Hours',
            reminder_at datetime DEFAULT NULL,
            due_at datetime DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempt_count int NOT NULL DEFAULT 0,
            last_attempt_at datetime DEFAULT NULL,
            next_retry_at datetime DEFAULT NULL,
            message_log_id bigint(20) unsigned DEFAULT 0,
            meta_message_id varchar(255) DEFAULT '',
            last_error text,
            sent_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY source_key (source_key),
            KEY task_date (task_id,occurrence_date),
            KEY status_retry (status,next_retry_at),
            KEY employee_id (employee_id)
        ) {$charset}");
    }

    private function ensure_math_table_integrity(){
        global $wpdb;
        $charset=$wpdb->get_charset_collate();

        // MathSpace is used from the frontend through admin-ajax.php. On a few upgraded
        // multisite installs the historical dbDelta migration could leave one of these
        // tables/columns missing, causing save_mathspace to return HTTP 500. Keep this
        // lightweight repair independent from the full plugin activation migration.
        $tables=[
            $this->tables['mathspaces'] => [
                'create' => "CREATE TABLE IF NOT EXISTS {$this->tables['mathspaces']} (
                    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                    name varchar(150) NOT NULL,
                    status varchar(20) NOT NULL DEFAULT 'active',
                    sort_order int NOT NULL DEFAULT 0,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    updated_at datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id), KEY sort_order (sort_order)
                ) {$charset}",
                'columns' => [
                    'name'       => "ALTER TABLE {$this->tables['mathspaces']} ADD COLUMN name varchar(150) NOT NULL AFTER id",
                    'status'     => "ALTER TABLE {$this->tables['mathspaces']} ADD COLUMN status varchar(20) NOT NULL DEFAULT 'active' AFTER name",
                    'sort_order' => "ALTER TABLE {$this->tables['mathspaces']} ADD COLUMN sort_order int NOT NULL DEFAULT 0 AFTER status",
                    'created_at' => "ALTER TABLE {$this->tables['mathspaces']} ADD COLUMN created_at datetime DEFAULT CURRENT_TIMESTAMP AFTER sort_order",
                    'updated_at' => "ALTER TABLE {$this->tables['mathspaces']} ADD COLUMN updated_at datetime DEFAULT CURRENT_TIMESTAMP AFTER created_at",
                ],
            ],
            $this->tables['math_areas'] => [
                'create' => "CREATE TABLE IF NOT EXISTS {$this->tables['math_areas']} (
                    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                    mathspace_id bigint(20) unsigned NOT NULL,
                    name varchar(150) NOT NULL,
                    color varchar(20) DEFAULT '#0ea5e9',
                    show_date tinyint(1) NOT NULL DEFAULT 0,
                    show_contact_number tinyint(1) NOT NULL DEFAULT 0,
                    sort_order int NOT NULL DEFAULT 0,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    updated_at datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id), KEY mathspace_id (mathspace_id), KEY sort_order (sort_order)
                ) {$charset}",
                'columns' => [
                    'mathspace_id' => "ALTER TABLE {$this->tables['math_areas']} ADD COLUMN mathspace_id bigint(20) unsigned NOT NULL AFTER id",
                    'name' => "ALTER TABLE {$this->tables['math_areas']} ADD COLUMN name varchar(150) NOT NULL AFTER mathspace_id",
                    'color' => "ALTER TABLE {$this->tables['math_areas']} ADD COLUMN color varchar(20) DEFAULT '#0ea5e9' AFTER name",
                    'show_date' => "ALTER TABLE {$this->tables['math_areas']} ADD COLUMN show_date tinyint(1) NOT NULL DEFAULT 0 AFTER color",
                    'show_contact_number' => "ALTER TABLE {$this->tables['math_areas']} ADD COLUMN show_contact_number tinyint(1) NOT NULL DEFAULT 0 AFTER show_date",
                    'sort_order' => "ALTER TABLE {$this->tables['math_areas']} ADD COLUMN sort_order int NOT NULL DEFAULT 0 AFTER show_contact_number",
                    'created_at' => "ALTER TABLE {$this->tables['math_areas']} ADD COLUMN created_at datetime DEFAULT CURRENT_TIMESTAMP AFTER sort_order",
                    'updated_at' => "ALTER TABLE {$this->tables['math_areas']} ADD COLUMN updated_at datetime DEFAULT CURRENT_TIMESTAMP AFTER created_at",
                ],
            ],
            $this->tables['math_entries'] => [
                'create' => "CREATE TABLE IF NOT EXISTS {$this->tables['math_entries']} (
                    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                    mathspace_id bigint(20) unsigned NOT NULL,
                    math_area_id bigint(20) unsigned NOT NULL,
                    particular varchar(255) NOT NULL,
                    entry_date date DEFAULT NULL,
                    number varchar(30) NOT NULL DEFAULT '',
                    amount decimal(18,2) NOT NULL DEFAULT 0.00,
                    sort_order int NOT NULL DEFAULT 0,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    updated_at datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id), KEY mathspace_id (mathspace_id), KEY math_area_id (math_area_id), KEY sort_order (sort_order)
                ) {$charset}",
                'columns' => [
                    'mathspace_id' => "ALTER TABLE {$this->tables['math_entries']} ADD COLUMN mathspace_id bigint(20) unsigned NOT NULL AFTER id",
                    'math_area_id' => "ALTER TABLE {$this->tables['math_entries']} ADD COLUMN math_area_id bigint(20) unsigned NOT NULL AFTER mathspace_id",
                    'particular' => "ALTER TABLE {$this->tables['math_entries']} ADD COLUMN particular varchar(255) NOT NULL AFTER math_area_id",
                    'entry_date' => "ALTER TABLE {$this->tables['math_entries']} ADD COLUMN entry_date date DEFAULT NULL AFTER particular",
                    'number' => "ALTER TABLE {$this->tables['math_entries']} ADD COLUMN number varchar(30) NOT NULL DEFAULT '' AFTER entry_date",
                    'amount' => "ALTER TABLE {$this->tables['math_entries']} ADD COLUMN amount decimal(18,2) NOT NULL DEFAULT 0.00 AFTER number",
                    'sort_order' => "ALTER TABLE {$this->tables['math_entries']} ADD COLUMN sort_order int NOT NULL DEFAULT 0 AFTER amount",
                    'created_at' => "ALTER TABLE {$this->tables['math_entries']} ADD COLUMN created_at datetime DEFAULT CURRENT_TIMESTAMP AFTER sort_order",
                    'updated_at' => "ALTER TABLE {$this->tables['math_entries']} ADD COLUMN updated_at datetime DEFAULT CURRENT_TIMESTAMP AFTER created_at",
                ],
            ],
        ];

        foreach($tables as $table=>$spec){
            $wpdb->query($spec['create']);
            $existing=$wpdb->get_col("SHOW COLUMNS FROM {$table}",0);
            if(!is_array($existing)) $existing=[];
            foreach($spec['columns'] as $column=>$sql){
                if(!in_array($column,$existing,true)) $wpdb->query($sql);
            }
        }
    }

    private function ensure_math_tables(){
        global $wpdb;
        $this->ensure_math_table_integrity();
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $charset=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$this->tables['mathspaces']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(150) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            sort_order int NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY sort_order (sort_order)
        ) $charset;");
        dbDelta("CREATE TABLE {$this->tables['math_areas']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            mathspace_id bigint(20) unsigned NOT NULL,
            name varchar(150) NOT NULL,
            color varchar(20) DEFAULT '#0ea5e9',
            show_date tinyint(1) NOT NULL DEFAULT 0,
            show_contact_number tinyint(1) NOT NULL DEFAULT 0,
            sort_order int NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY mathspace_id (mathspace_id), KEY sort_order (sort_order)
        ) $charset;");
        dbDelta("CREATE TABLE {$this->tables['math_entries']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            mathspace_id bigint(20) unsigned NOT NULL,
            math_area_id bigint(20) unsigned NOT NULL,
            particular varchar(255) NOT NULL,
            entry_date date DEFAULT NULL,
            number varchar(30) NOT NULL DEFAULT '',
            amount decimal(18,2) NOT NULL DEFAULT 0.00,
            sort_order int NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY mathspace_id (mathspace_id), KEY math_area_id (math_area_id), KEY sort_order (sort_order)
        ) $charset;");
    }

    private function additional_task_reminder_rules($task_id){
        global $wpdb;
        $this->ensure_task_reminders_table();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id,task_id,reminder_value,reminder_unit,sort_order FROM {$this->tables['task_reminders']} WHERE task_id=%d ORDER BY sort_order ASC,id ASC",
            absint($task_id)
        ));
    }

    private function sync_additional_task_reminders($task_id,$rules){
        global $wpdb;
        $this->ensure_task_reminders_table();
        $task_id=absint($task_id);
        $wpdb->delete($this->tables['task_reminders'],['task_id'=>$task_id]);
        $seen=[];$sort=1;
        foreach(array_slice((array)$rules,1) as $rule){
            $unit=sanitize_text_field($rule['unit']??$rule['reminder_unit']??'Hours');
            if(!in_array($unit,['Minutes','Hours','Days','Weeks'],true)) $unit='Hours';
            $value=max($unit==='Minutes'?5:1,min(9999,absint($rule['value']??$rule['reminder_value']??1)));
            $key=$value.'|'.$unit;
            if(isset($seen[$key])) continue;
            $seen[$key]=1;
            $wpdb->insert($this->tables['task_reminders'],[
                'task_id'=>$task_id,'reminder_value'=>$value,'reminder_unit'=>$unit,'sort_order'=>$sort++,
                'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')
            ]);
        }
    }

    private function all_task_reminder_rules($task){
        if(!$task || empty($task->reminder_enabled)) return [];
        $rules=[];
        $unit=in_array(($task->reminder_unit??'Hours'),['Minutes','Hours','Days','Weeks'],true)?$task->reminder_unit:'Hours';
        $rules[]=(object)['id'=>0,'reminder_value'=>max($unit==='Minutes'?5:1,absint($task->reminder_value??1)),'reminder_unit'=>$unit,'sort_order'=>0,'is_primary'=>1];
        foreach($this->additional_task_reminder_rules((int)$task->id) as $r){ $r->is_primary=0; $rules[]=$r; }
        return $rules;
    }

    private function normalize_posted_reminders($raw,$due_time=''){
        $decoded=is_array($raw)?$raw:json_decode(wp_unslash((string)$raw),true);
        if(!is_array($decoded)) return [];
        $out=[];$seen=[];
        foreach(array_slice($decoded,0,10) as $r){
            if(!is_array($r)) continue;
            $unit=sanitize_text_field($r['unit']??$r['reminder_unit']??'Hours');
            if(!in_array($unit,['Minutes','Hours','Days','Weeks'],true)) $unit='Hours';
            $value=max($unit==='Minutes'?5:1,min(9999,absint($r['value']??$r['reminder_value']??1)));
            if($unit==='Minutes' && !$due_time) continue;
            $key=$value.'|'.$unit;
            if(isset($seen[$key])) continue;
            $seen[$key]=1;$out[]=['value'=>$value,'unit'=>$unit];
        }
        return $out;
    }

    public function ensure_reminder_cron(){
        // WP-Cron still needs a request to wp-cron.php. Configure the real Hostinger/server cron
        // to call the MAIN site's wp-cron.php every 5 minutes (*/5 * * * *). Keep a matching
        // WordPress event for single-site/subsite traffic and guarantee the main-site event in multisite.
        $ensure_five_minute_event=function(){
            $event=function_exists('wp_get_scheduled_event')?wp_get_scheduled_event('bb_task_reminder_tick'):null;
            if(!$event || ($event->schedule??'')!=='bb_every_five_minutes'){
                // Clears legacy every-minute events so the configured cadence is truly five minutes.
                wp_clear_scheduled_hook('bb_task_reminder_tick');
                wp_schedule_event(time()+60,'bb_every_five_minutes','bb_task_reminder_tick');
            }
        };
        $ensure_five_minute_event();
        if(is_multisite() && !is_main_site()){
            $origin=(int)get_current_blog_id();
            $main=(int)get_main_site_id();
            if($main && $main!==$origin){
                switch_to_blog($main);
                $ensure_five_minute_event();
                restore_current_blog();
                $this->refresh_site_tables();
            }
        }
    }

    public function maybe_run_reminder_fallback(){
        // Do not compete with the real cron process itself. Also never process reminders
        // at the end of AJAX mutations such as Add/Edit/Save Task or Save Reminder.
        // Due reminders must be sent only by the scheduled cron/fallback page-load cycle,
        // not as a side-effect of saving task data.
        if((defined('DOING_CRON') && DOING_CRON) || (defined('WP_CLI') && WP_CLI) || (function_exists('wp_doing_ajax') && wp_doing_ajax())) return;
        $last=(int)get_option('bb_last_reminder_tick',0);
        if($last && $last>=time()-330) return;
        global $wpdb;
        $tasks_table=$this->tables['tasks']??'';
        if(!$tasks_table || $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$tasks_table))!==$tasks_table) return;
        $this->ensure_task_overdue_reminders_table();
        $has_due=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->tables['task_overdue_reminders']} WHERE enabled=1");
        $has_before=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->tables['tasks']} WHERE reminder_enabled=1 AND archived_at IS NULL");
        if(!$has_due && !$has_before) return;
        $lock='bb_reminder_fallback_lock_'.get_current_blog_id();
        if(get_transient($lock)) return;
        set_transient($lock,1,290);
        try{
            $this->run_task_reminder_tick_for_current_site();
        }catch(\Throwable $e){
            error_log('Workboard reminder fallback: '.$e->getMessage());
        }finally{
            delete_transient($lock);
        }
    }

    public function cron_schedules($schedules){
        if(!isset($schedules['bb_every_five_minutes'])) $schedules['bb_every_five_minutes']=['interval'=>5*MINUTE_IN_SECONDS,'display'=>'Workboard Every 5 Minutes'];
        // Compatibility only: old queued events are migrated by ensure_reminder_cron().
        if(!isset($schedules['bb_every_minute'])) $schedules['bb_every_minute']=['interval'=>MINUTE_IN_SECONDS,'display'=>'Workboard Legacy Every Minute'];
        return $schedules;
    }

    private function reminder_offset_seconds($value,$unit){
        $value=max(1,absint($value));
        if($unit==='Minutes') return $value*MINUTE_IN_SECONDS;
        if($unit==='Hours') return $value*HOUR_IN_SECONDS;
        if($unit==='Days') return $value*DAY_IN_SECONDS;
        if($unit==='Weeks') return $value*WEEK_IN_SECONDS;
        return $value*HOUR_IN_SECONDS;
    }

    private function reminder_datetime_for($task,$occurrence_date,$ist,$rule=null){
        if(!$task || !$occurrence_date) return null;
        $unit=$rule?($rule->reminder_unit??'Hours'):($task->reminder_unit??'Hours');
        $unit=in_array($unit,['Minutes','Hours','Days','Weeks'],true)?$unit:'Hours';
        $value=max(1,absint($rule?($rule->reminder_value??1):($task->reminder_value??1)));
        $due_time=trim((string)($task->due_time??''));
        if($unit==='Minutes' && !$due_time) return null;
        try{
            // A blank visible Due Time is intentionally preserved. For server-side reminder
            // calculation only, Workboard treats the task as due at 10:00 AM.
            $effective_due_time=$due_time?substr($due_time,0,5):'10:00';
            $due=new DateTime($occurrence_date.' '.$effective_due_time.':00',$ist);
            $reminder=clone $due;
            $reminder->modify('-'.$value.' '.strtolower($unit));
            return [$reminder,$due];
        }catch(Exception $e){ return null; }
    }

    private function reminder_source_key($task,$occurrence_date,$rule,$employee_id){
        $value=max(1,(int)($rule->reminder_value??1));
        $unit=strtolower((string)($rule->reminder_unit??'Hours'));
        $unit=preg_replace('/[^a-z]/','',$unit)?:'hours';
        $due_time=trim((string)($task->due_time??''));
        // Include the effective due time as well. If a task's due time changes after an
        // earlier reminder was delivered, the newly scheduled reminder gets a new identity.
        $due_token=$due_time?preg_replace('/\D+/','',substr($due_time,0,5)):'1000';
        return sanitize_key('task_reminder_'.absint($task->id).'_'.$occurrence_date.'_'.$due_token.'_'.$value.'_'.$unit.'_'.absint($employee_id));
    }

    private function overdue_reminder_due_at($task,$occurrence_date,$ist){
        if(!$task || !$occurrence_date) return null;
        try{
            $due_time=trim((string)($task->due_time??''));
            $effective=$due_time?substr($due_time,0,5):'10:00';
            return new DateTime($occurrence_date.' '.$effective.':00',$ist);
        }catch(Exception $e){ return null; }
    }

    private function overdue_time_in_active_window(DateTime $dt,$cfg){
        if(!empty($cfg->all_day)) return true;
        $start=substr((string)($cfg->active_start?:'08:00:00'),0,5);
        $end=substr((string)($cfg->active_end?:'20:00:00'),0,5);
        $clock=$dt->format('H:i');
        // Equal start/end is treated as a 24-hour window. Overnight windows are supported.
        if($start===$end) return true;
        if($start<$end) return $clock>=$start && $clock<=$end;
        return $clock>=$start || $clock<=$end;
    }

    private function overdue_reminder_candidate($task,$occurrence_date,$cfg,DateTime $now,$ist){
        if(!$cfg || empty($cfg->enabled)) return null;
        $due=$this->overdue_reminder_due_at($task,$occurrence_date,$ist);
        if(!$due || $now<=$due) return null;
        $duration=max(0,(int)($cfg->duration_days??0));
        // 3651 is the UI sentinel for "Same Day": after-due reminders are allowed
        // only on the calendar date of the occurrence (still respecting Active Hours).
        if($duration===3651){
            if($now->format('Y-m-d')!==$due->format('Y-m-d')) return null;
        }elseif($duration){
            $deadline=clone $due; $deadline->modify('+'.$duration.' days');
            if($now>$deadline) return null;
        }
        if(!$this->overdue_time_in_active_window($now,$cfg)) return null;
        $unit=in_array(($cfg->interval_unit??'Hours'),['Minutes','Hours','Days','Weeks'],true)?$cfg->interval_unit:'Hours';
        $value=max(1,(int)($cfg->interval_value??1));
        $seconds=$this->reminder_offset_seconds($value,$unit);
        if($seconds<60) $seconds=60;
        $elapsed=$now->getTimestamp()-$due->getTimestamp();
        $slot=(int)floor($elapsed/$seconds);
        if($slot<1) return null;
        $scheduled=clone $due;
        $scheduled->setTimestamp($due->getTimestamp()+($slot*$seconds));
        // Preserve the cadence from the Due Time. If the slot falls outside Active Hours,
        // do not shift it: the next cadence slot inside the window will be used instead.
        if(!$this->overdue_time_in_active_window($scheduled,$cfg)) return null;
        return [$scheduled,$due,$slot];
    }

    private function overdue_reminder_source_key($task,$occurrence_date,DateTime $scheduled,$employee_id){
        $due_time=trim((string)($task->due_time??''));
        $due_token=$due_time?preg_replace('/\D+/','',substr($due_time,0,5)):'1000';
        return sanitize_key('task_overdue_reminder_'.absint($task->id).'_'.$occurrence_date.'_'.$due_token.'_'.$scheduled->format('YmdHi').'_'.absint($employee_id));
    }

    private function overdue_reminder_sent_count($task_id,$occurrence_date,$employee_id){
        global $wpdb;
        $prefix=$wpdb->esc_like('task_overdue_reminder_'.absint($task_id).'_'.sanitize_text_field($occurrence_date).'_').'%';
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tables['reminder_delivery']} WHERE task_id=%d AND occurrence_date=%s AND employee_id=%d AND status='sent' AND source_key LIKE %s",
            absint($task_id),sanitize_text_field($occurrence_date),absint($employee_id),$prefix
        ));
    }

    private function reminder_slot_source_key($task_id,$occurrence_date,$employee_id,DateTime $scheduled){
        // One canonical lock per Task + occurrence + recipient + scheduled minute.
        // MD5 keeps source_key comfortably below the unique-index length.
        $minute=$scheduled->format('YmdHi');
        return 'reminder_slot_'.md5(
            absint($task_id).'|'.sanitize_text_field($occurrence_date).'|'.absint($employee_id).'|'.$minute
        );
    }

    private function claim_scheduled_reminder_slot($task_id,$occurrence_date,$employee_id,DateTime $scheduled,DateTime $due_at,DateTime $now){
        global $wpdb;
        $this->ensure_reminder_delivery_table();

        $slot=$this->reminder_slot_source_key($task_id,$occurrence_date,$employee_id,$scheduled);
        $minute=clone $scheduled;
        $minute->setTime((int)$scheduled->format('H'),(int)$scheduled->format('i'),0);
        $now_sql=$now->format('Y-m-d H:i:s');

        // UNIQUE source_key makes this an atomic claim across overlapping cron
        // requests / PHP workers. Exactly one worker can insert this slot.
        $inserted=$wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$this->tables['reminder_delivery']}
             (source_key,task_id,occurrence_date,employee_id,reminder_value,reminder_unit,reminder_at,due_at,status,attempt_count,last_attempt_at,created_at,updated_at)
             VALUES (%s,%d,%s,%d,0,'Slot',%s,%s,'attempting',1,%s,%s,%s)",
            $slot,absint($task_id),sanitize_text_field($occurrence_date),absint($employee_id),
            $minute->format('Y-m-d H:i:s'),$due_at->format('Y-m-d H:i:s'),
            $now_sql,$now_sql,$now_sql
        ));
        if((int)$inserted===1) return $slot;

        $existing=$wpdb->get_row($wpdb->prepare(
            "SELECT id,status,updated_at FROM {$this->tables['reminder_delivery']} WHERE source_key=%s LIMIT 1",
            $slot
        ));
        if(!$existing) return false;

        // If a previous process died while holding the lock, allow a retry after
        // 3 minutes. Normal overlapping cron requests remain blocked immediately.
        if((string)$existing->status==='attempting' && !empty($existing->updated_at)){
            $stale=(clone $now)->modify('-3 minutes')->format('Y-m-d H:i:s');
            if((string)$existing->updated_at<$stale){
                $claimed=$wpdb->query($wpdb->prepare(
                    "UPDATE {$this->tables['reminder_delivery']}
                     SET status='attempting',attempt_count=attempt_count+1,last_attempt_at=%s,updated_at=%s
                     WHERE id=%d AND status='attempting' AND updated_at<%s",
                    $now_sql,$now_sql,(int)$existing->id,$stale
                ));
                if((int)$claimed===1) return $slot;
            }
        }

        // Failed slots can be reclaimed. Successful / live slots block duplicates.
        if((string)$existing->status==='failed'){
            $claimed=$wpdb->query($wpdb->prepare(
                "UPDATE {$this->tables['reminder_delivery']}
                 SET status='attempting',attempt_count=attempt_count+1,last_attempt_at=%s,last_error='',updated_at=%s
                 WHERE id=%d AND status='failed'",
                $now_sql,$now_sql,(int)$existing->id
            ));
            if((int)$claimed===1) return $slot;
        }

        return false;
    }

    private function finish_scheduled_reminder_slot($slot_source,$success,DateTime $now,$error=''){
        global $wpdb;
        if(!$slot_source) return;
        $now_sql=$now->format('Y-m-d H:i:s');

        if($success){
            $wpdb->update($this->tables['reminder_delivery'],[
                'status'=>'sent',
                'last_error'=>'',
                'sent_at'=>$now_sql,
                'updated_at'=>$now_sql
            ],['source_key'=>$slot_source]);
        }else{
            // Keep the slot retryable instead of permanently blocking delivery.
            $wpdb->update($this->tables['reminder_delivery'],[
                'status'=>'failed',
                'last_error'=>sanitize_text_field($error),
                'updated_at'=>$now_sql
            ],['source_key'=>$slot_source]);
        }
    }


    private function attempt_overdue_reminder_delivery($task,$occurrence_date,$cfg,$employee_id,DateTime $scheduled,DateTime $due_at,DateTime $now,$message){
        global $wpdb;
        $this->ensure_reminder_delivery_table();
        $source=$this->overdue_reminder_source_key($task,$occurrence_date,$scheduled,$employee_id);
        $max=max(0,(int)($cfg->max_reminders??0));
        if($max && $this->overdue_reminder_sent_count((int)$task->id,$occurrence_date,(int)$employee_id)>=$max) return ['state'=>'max_reached'];
        $value=max(1,(int)($cfg->interval_value??1));
        $unit=in_array(($cfg->interval_unit??'Hours'),['Minutes','Hours','Days','Weeks'],true)?$cfg->interval_unit:'Hours';
        $scheduled_sql=$scheduled->format('Y-m-d H:i:s');
        $due_sql=$due_at->format('Y-m-d H:i:s');
        $now_sql=$now->format('Y-m-d H:i:s');
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$this->tables['reminder_delivery']}
             (source_key,task_id,occurrence_date,employee_id,reminder_value,reminder_unit,reminder_at,due_at,status,attempt_count,created_at,updated_at)
             VALUES (%s,%d,%s,%d,%d,%s,%s,%s,'pending',0,%s,%s)",
            $source,(int)$task->id,$occurrence_date,(int)$employee_id,$value,$unit,$scheduled_sql,$due_sql,$now_sql,$now_sql
        ));
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['reminder_delivery']} WHERE source_key=%s LIMIT 1",$source));
        if(!$row) return ['state'=>'failed','error'=>'Overdue reminder delivery record could not be created.'];
        if((string)$row->status==='sent') return ['state'=>'already_sent'];
        if(!empty($row->next_retry_at) && $now_sql<(string)$row->next_retry_at) return ['state'=>'deferred'];

        $slot_source=$this->claim_scheduled_reminder_slot((int)$task->id,$occurrence_date,(int)$employee_id,$scheduled,$due_at,$now);
        if(!$slot_source){
            // Another worker / reminder rule already owns this exact scheduled minute.
            if((string)$row->status!=='sent'){
                $wpdb->update($this->tables['reminder_delivery'],[
                    'status'=>'skipped',
                    'last_error'=>'Duplicate reminder suppressed by atomic scheduled-minute lock.',
                    'next_retry_at'=>null,
                    'updated_at'=>$now_sql
                ],['id'=>(int)$row->id]);
            }
            return ['state'=>'duplicate_suppressed'];
        }

        $attempt=max(0,(int)$row->attempt_count)+1;
        $claimed=$wpdb->query($wpdb->prepare(
            "UPDATE {$this->tables['reminder_delivery']}
             SET status='attempting',attempt_count=%d,last_attempt_at=%s,updated_at=%s
             WHERE id=%d AND status<>'sent'",
            $attempt,$now_sql,$now_sql,(int)$row->id
        ));
        if($claimed===false){
            $this->finish_scheduled_reminder_slot($slot_source,false,$now,'Could not claim reminder delivery record.');
            return ['state'=>'failed','error'=>'Could not claim reminder delivery record.'];
        }
        $emp=$this->ensure_internal_task_reminder_notification($employee_id,$task,$occurrence_date,$message,$source);
        if(!$emp) $result=['ok'=>false,'message'=>'Assignee record was not found.'];
        elseif(empty($emp->mobile)) $result=['ok'=>false,'message'=>'Assignee mobile number is missing.'];
        else $result=$this->send_meta_whatsapp($emp->mobile,'Task Due Reminder',$message,'after_due_reminder',false,(int)$task->id,$occurrence_date,(int)$employee_id,$source);
        $log_id=absint($result['message_log_id']??0);
        if(empty($result['ok'])){
            $error=sanitize_text_field($result['message']??'WhatsApp overdue reminder could not be sent.');
            if(!$log_id) $log_id=$this->reminder_delivery_failure_log((int)$employee_id,$emp->mobile??'', $task,$occurrence_date,$source,$message,$error,'after_due_reminder','Task Due Reminder');
            $retry_minutes=min(60,5*(2**min(4,max(0,$attempt-1))));
            $next=clone $now; $next->modify('+'.$retry_minutes.' minutes');
            $wpdb->update($this->tables['reminder_delivery'],[
                'status'=>'failed','last_error'=>$error,'message_log_id'=>$log_id,'meta_message_id'=>'','next_retry_at'=>$next->format('Y-m-d H:i:s'),'updated_at'=>$now_sql
            ],['id'=>(int)$row->id]);
            $this->finish_scheduled_reminder_slot($slot_source,false,$now,$error);
            return ['state'=>'failed','error'=>$error,'attempt'=>$attempt,'message_log_id'=>$log_id];
        }
        $meta_id=sanitize_text_field($result['meta_message_id']??'');
        $wpdb->update($this->tables['reminder_delivery'],[
            'status'=>'sent','last_error'=>'','message_log_id'=>$log_id,'meta_message_id'=>$meta_id,'next_retry_at'=>null,'sent_at'=>$now_sql,'updated_at'=>$now_sql
        ],['id'=>(int)$row->id]);
        $this->finish_scheduled_reminder_slot($slot_source,true,$now);
        return ['state'=>'sent','attempt'=>$attempt,'message_log_id'=>$log_id,'meta_message_id'=>$meta_id];
    }

    private function current_overdue_occurrence_for_reminder($task,$today,DateTime $now,$ist,$cfg){
        global $wpdb;
        if(!(int)$task->is_template && $task->task_type!=='Recurring') return !empty($task->due_date)?(string)$task->due_date:null;
        if(($task->recurrence_status??'active')!=='active' || empty($task->start_date)) return null;
        $duration=max(0,(int)($cfg->duration_days??0));
        $from=$duration===3651?$today:($duration?(clone $now)->modify('-'.($duration+2).' days')->format('Y-m-d'):(string)$task->start_date);
        if($from>$today) $from=$today;
        $dates=$this->occurrence_dates($task,$from,$today);
        rsort($dates);
        foreach($dates as $date){
            if(!$this->recurring_overdue_visible($task,$date,$today) && $date!==$today) continue;
            $status=$wpdb->get_var($wpdb->prepare("SELECT status FROM {$this->tables['history']} WHERE task_id=%d AND occurrence_date=%s LIMIT 1",(int)$task->id,$date));
            if($status==='Completed') continue;
            $due=$this->overdue_reminder_due_at($task,$date,$ist);
            if($due && $now>$due) return $date;
        }
        return null;
    }

    private function ensure_internal_task_reminder_notification($employee_id,$task,$occurrence_date,$message,$source_key){
        global $wpdb;
        $employee_id=absint($employee_id);
        $emp=$employee_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['employees']} WHERE id=%d",$employee_id)):null;
        if(!$emp) return null;
        $created=$wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$this->tables['notifications']} (employee_id,wp_user_id,workspace_id,task_id,occurrence_date,type,title,message,source_key,is_read,created_at)
             VALUES (%d,%s,%s,%s,%s,'task_reminder','Task Reminder',%s,%s,0,%s)",
            $employee_id,$emp->wp_user_id?:null,$task->workspace_id?:null,$task->id?:null,$occurrence_date?:null,sanitize_text_field($message),sanitize_text_field($source_key),current_time('mysql')
        ));
        // Email is sent only on the first creation of the in-app reminder. WhatsApp retries
        // below are independent, so a failed WhatsApp attempt never repeats the email.
        if($created && get_option('bb_notify_email',0) && !empty($emp->email)) wp_mail($emp->email,'Workboard: Task Reminder',$message);
        return $emp;
    }

    private function reminder_delivery_failure_log($employee_id,$mobile,$task,$occurrence_date,$source_key,$message,$error,$message_type='task_reminder',$title='Task Reminder'){
        $creds=$this->resolved_messaging_credentials();
        $message_type=in_array($message_type,['task_reminder','after_due_reminder'],true)?$message_type:'task_reminder';
        $log_id=$this->message_log_create([
            'task_id'=>(int)$task->id,'recipient_employee_id'=>$employee_id,'mobile'=>$this->whatsapp_normalize_number($mobile,$creds['country_code']??'91'),
            'message_type'=>$message_type,'template_name'=>$this->whatsapp_template_name($message_type),'template_category'=>'utility','title'=>$title,
            'parameters'=>$this->whatsapp_template_parameters($message_type,$title,$message,(int)$task->id,$occurrence_date,false,$employee_id),
            'auth_mode'=>$creds['mode']??'disabled','trigger_type'=>'scheduled','source_key'=>$source_key
        ]);
        $this->message_log_finish($log_id,'failed','',0,$error,0);
        return $log_id;
    }

    private function attempt_task_reminder_delivery($task,$occurrence_date,$rule,$employee_id,$reminder_at,$due_at,$now,$message){
        global $wpdb;
        $this->ensure_reminder_delivery_table();
        $source=$this->reminder_source_key($task,$occurrence_date,$rule,$employee_id);
        $value=max(1,(int)($rule->reminder_value??1));
        $unit=in_array(($rule->reminder_unit??'Hours'),['Minutes','Hours','Days','Weeks'],true)?$rule->reminder_unit:'Hours';
        $reminder_sql=$reminder_at->format('Y-m-d H:i:s');
        $due_sql=$due_at->format('Y-m-d H:i:s');
        $now_sql=$now->format('Y-m-d H:i:s');

        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$this->tables['reminder_delivery']}
             (source_key,task_id,occurrence_date,employee_id,reminder_value,reminder_unit,reminder_at,due_at,status,attempt_count,created_at,updated_at)
             VALUES (%s,%d,%s,%d,%d,%s,%s,%s,'pending',0,%s,%s)",
            $source,(int)$task->id,$occurrence_date,(int)$employee_id,$value,$unit,$reminder_sql,$due_sql,$now_sql,$now_sql
        ));
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['reminder_delivery']} WHERE source_key=%s LIMIT 1",$source));
        if(!$row) return ['state'=>'failed','error'=>'Reminder delivery record could not be created.'];
        if((string)$row->status==='sent') return ['state'=>'already_sent'];
        if($now>$due_at){
            $wpdb->update($this->tables['reminder_delivery'],['status'=>'skipped','last_error'=>'Reminder window expired before successful delivery.','next_retry_at'=>null,'updated_at'=>$now_sql],['id'=>(int)$row->id]);
            return ['state'=>'expired'];
        }
        if(!empty($row->next_retry_at) && $now_sql<(string)$row->next_retry_at) return ['state'=>'deferred'];

        $slot_source=$this->claim_scheduled_reminder_slot((int)$task->id,$occurrence_date,(int)$employee_id,$reminder_at,$due_at,$now);
        if(!$slot_source){
            if((string)$row->status!=='sent'){
                $wpdb->update($this->tables['reminder_delivery'],[
                    'status'=>'skipped',
                    'last_error'=>'Duplicate reminder suppressed by atomic scheduled-minute lock.',
                    'next_retry_at'=>null,
                    'updated_at'=>$now_sql
                ],['id'=>(int)$row->id]);
            }
            return ['state'=>'duplicate_suppressed'];
        }

        $attempt=max(0,(int)$row->attempt_count)+1;
        $claimed=$wpdb->query($wpdb->prepare(
            "UPDATE {$this->tables['reminder_delivery']}
             SET status='attempting',attempt_count=%d,last_attempt_at=%s,updated_at=%s
             WHERE id=%d AND status<>'sent'",
            $attempt,$now_sql,$now_sql,(int)$row->id
        ));
        if($claimed===false){
            $this->finish_scheduled_reminder_slot($slot_source,false,$now,'Could not claim reminder delivery record.');
            return ['state'=>'failed','error'=>'Could not claim reminder delivery record.'];
        }

        $emp=$this->ensure_internal_task_reminder_notification($employee_id,$task,$occurrence_date,$message,$source);
        if(!$emp){
            $result=['ok'=>false,'message'=>'Assignee record was not found.'];
        }elseif(empty($emp->mobile)){
            $result=['ok'=>false,'message'=>'Assignee mobile number is missing.'];
        }else{
            // This call is independent of INSERT IGNORE in bb_notifications. A Meta/API/wallet
            // failure therefore remains retryable instead of being permanently suppressed.
            $result=$this->send_meta_whatsapp($emp->mobile,'Task Reminder',$message,'task_reminder',false,(int)$task->id,$occurrence_date,(int)$employee_id,$source);
        }

        $log_id=absint($result['message_log_id']??0);
        if(empty($result['ok'])){
            $error=sanitize_text_field($result['message']??'WhatsApp reminder could not be sent.');
            if(!$log_id) $log_id=$this->reminder_delivery_failure_log((int)$employee_id,$emp->mobile??'', $task,$occurrence_date,$source,$message,$error);
            // Exponential retry: 5, 10, 20, 40, then at most every 60 minutes, but never
            // after the task's due time. This keeps temporary wallet/token/API failures retryable
            // without flooding the message log every minute.
            $retry_minutes=min(60,5*(2**min(4,max(0,$attempt-1))));
            $next=clone $now; $next->modify('+'.$retry_minutes.' minutes');
            if($next>$due_at) $next=clone $due_at;
            $wpdb->update($this->tables['reminder_delivery'],[
                'status'=>'failed','last_error'=>$error,'message_log_id'=>$log_id,'meta_message_id'=>'','next_retry_at'=>$next->format('Y-m-d H:i:s'),'updated_at'=>$now_sql
            ],['id'=>(int)$row->id]);
            $this->finish_scheduled_reminder_slot($slot_source,false,$now,$error);
            return ['state'=>'failed','error'=>$error,'attempt'=>$attempt,'message_log_id'=>$log_id];
        }

        $meta_id=sanitize_text_field($result['meta_message_id']??'');
        $wpdb->update($this->tables['reminder_delivery'],[
            'status'=>'sent','last_error'=>'','message_log_id'=>$log_id,'meta_message_id'=>$meta_id,'next_retry_at'=>null,'sent_at'=>$now_sql,'updated_at'=>$now_sql
        ],['id'=>(int)$row->id]);
        $this->finish_scheduled_reminder_slot($slot_source,true,$now);
        return ['state'=>'sent','attempt'=>$attempt,'message_log_id'=>$log_id,'meta_message_id'=>$meta_id];
    }

    // v4.16.142: Sunday & Holiday Reminder uses the Attendance > Holiday master.
    // When the task opt-in is OFF, scheduled task reminders are suppressed on both Sundays
    // and dates entered in bb_attendance_holidays. Results are cached for the current request.
    private function reminder_date_is_sunday_or_holiday($date){
        global $wpdb;
        $date=sanitize_text_field((string)$date);
        if(!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) return false;
        static $cache=[];
        if(array_key_exists($date,$cache)) return $cache[$date];
        $dt=DateTime::createFromFormat('!Y-m-d',$date,new DateTimeZone('Asia/Kolkata'));
        if($dt && $dt->format('w')==='0') return $cache[$date]=true;
        $table=$this->tables['attendance_holidays']??($wpdb->prefix.'bb_attendance_holidays');
        $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table));
        if($exists!==$table) return $cache[$date]=false;
        $is_holiday=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE holiday_date=%s",$date))>0;
        return $cache[$date]=$is_holiday;
    }

    private function run_task_reminder_tick_for_current_site(){
        global $wpdb;
        $tasks_table=$this->tables['tasks'];
        if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$tasks_table))!==$tasks_table) return;
        $this->ensure_task_reminders_table();
        $this->ensure_task_overdue_reminders_table();
        $this->ensure_reminder_delivery_table();
        $this->ensure_current_site_messaging_config();
        update_option('bb_last_reminder_tick',time(),false);
        $this->maybe_send_attendance_summary();

        $ist=new DateTimeZone('Asia/Kolkata');
        $now=new DateTime('now',$ist); $today=$now->format('Y-m-d');
        $tasks=$wpdb->get_results("SELECT DISTINCT t.* FROM {$this->tables['tasks']} t LEFT JOIN {$this->tables['task_overdue_reminders']} odr ON odr.task_id=t.id LEFT JOIN {$this->tables['work_areas']} a ON a.id=t.work_area_id LEFT JOIN {$this->tables['workspaces']} w ON w.id=t.workspace_id WHERE (t.reminder_enabled=1 OR odr.enabled=1) AND t.archived_at IS NULL AND t.status NOT IN ('Completed','Archived','Cancelled') AND (a.status IS NULL OR a.status NOT IN ('trash','archived')) AND (w.status IS NULL OR w.status NOT IN ('trash','archived'))");
        $overdue_rows=$wpdb->get_results("SELECT * FROM {$this->tables['task_overdue_reminders']} WHERE enabled=1");
        $overdue_map=[]; foreach((array)$overdue_rows as $orow) $overdue_map[(int)$orow->task_id]=$orow;
        foreach((array)$tasks as $task){
            $rules=$this->all_task_reminder_rules($task);
            $overdue_cfg=$overdue_map[(int)$task->id]??(object)['enabled'=>0];
            // v4.16.142: Sunday/Holiday delivery is task-specific and opt-in only.
            // Existing tasks default to OFF. Skip Before Due and After Due/Repeat reminders
            // when today is Sunday or is listed in Attendance > Holiday.
            if(empty($task->sunday_reminder_enabled) && $this->reminder_date_is_sunday_or_holiday($today)) continue;

            // BEFORE DUE reminders: preserve the existing behavior exactly.
            if($rules){
                $max_lookahead=2;
                foreach($rules as $rule){
                    $unit=in_array(($rule->reminder_unit??'Hours'),['Minutes','Hours','Days','Weeks'],true)?$rule->reminder_unit:'Hours';
                    $value=max(1,absint($rule->reminder_value??1));
                    if($unit==='Minutes' && empty($task->due_time)) continue;
                    if($unit==='Hours') $max_lookahead=max($max_lookahead,(int)ceil($value/24)+2);
                    elseif($unit==='Days') $max_lookahead=max($max_lookahead,$value+2);
                    elseif($unit==='Weeks') $max_lookahead=max($max_lookahead,($value*7)+2);
                }
                $dates=[];
                if((int)$task->is_template || $task->task_type==='Recurring'){
                    if(($task->recurrence_status??'active')==='active' && !empty($task->start_date)){
                        $to=(clone $now)->modify('+'.$max_lookahead.' days')->format('Y-m-d');
                        $dates=$this->occurrence_dates($task,$today,$to);
                    }
                }elseif(!empty($task->due_date)) $dates=[(string)$task->due_date];
                foreach($dates as $date){
                    if((int)$task->is_template || $task->task_type==='Recurring'){
                        $occ_status=$wpdb->get_var($wpdb->prepare("SELECT status FROM {$this->tables['history']} WHERE task_id=%d AND occurrence_date=%s LIMIT 1",(int)$task->id,$date));
                        if($occ_status==='Completed') continue;
                    }
                    $assignees=$this->task_assignee_employee_ids((int)$task->id,(int)($task->assignee_id??0));
                    if(!$assignees) break;
                    $due_text=date_i18n('d M Y',strtotime($date));
                    if(!empty($task->due_time)) $due_text.=' '.substr((string)$task->due_time,0,5);
                    $handled_date=false;
                    foreach($rules as $rule){
                        $window=$this->reminder_datetime_for($task,$date,$ist,$rule); if(!$window) continue;
                        [$reminder_at,$due_at]=$window;
                        if(empty($task->sunday_reminder_enabled) && $this->reminder_date_is_sunday_or_holiday($reminder_at->format('Y-m-d'))) continue;
                        if($now<$reminder_at || $now>$due_at) continue;
                        $handled_date=true;$sent=0;$failed=0;
                        $message='Reminder: '.$task->task_name.' is due '.$due_text.'.';
                        foreach($assignees as $eid){
                            $delivery=$this->attempt_task_reminder_delivery($task,$date,$rule,$eid,$reminder_at,$due_at,$now,$message);
                            if(($delivery['state']??'')==='sent') $sent++;
                            elseif(($delivery['state']??'')==='failed') $failed++;
                        }
                        if($sent) $this->log_activity('task_reminder_sent','Reminder sent to '.$sent.' Assignee'.($sent===1?'':'s').' · '.max(1,(int)$rule->reminder_value).' '.$rule->reminder_unit.' before',$task->workspace_id,$task->work_area_id,$task->id,$date);
                        if($failed) $this->log_activity('task_reminder_failed','Reminder failed for '.$failed.' Assignee'.($failed===1?'':'s').' · retry scheduled',$task->workspace_id,$task->work_area_id,$task->id,$date);
                    }
                    if($handled_date) break;
                }
            }

            // AFTER DUE reminders: repeat on the selected cadence on future days too,
            // but only while the occurrence remains incomplete.
            if(empty($overdue_cfg->enabled)) continue;
            $date=$this->current_overdue_occurrence_for_reminder($task,$today,$now,$ist,$overdue_cfg);
            if(!$date) continue;
            if((int)$task->is_template || $task->task_type==='Recurring'){
                $occ_status=$wpdb->get_var($wpdb->prepare("SELECT status FROM {$this->tables['history']} WHERE task_id=%d AND occurrence_date=%s LIMIT 1",(int)$task->id,$date));
                if($occ_status==='Completed') continue;
            }elseif(in_array((string)$task->status,['Completed','Archived','Cancelled'],true)) continue;
            $candidate=$this->overdue_reminder_candidate($task,$date,$overdue_cfg,$now,$ist);
            if(!$candidate) continue;
            [$scheduled,$due_at,$slot]=$candidate;
            if(empty($task->sunday_reminder_enabled) && $this->reminder_date_is_sunday_or_holiday($scheduled->format('Y-m-d'))) continue;
            $assignees=$this->task_assignee_employee_ids((int)$task->id,(int)($task->assignee_id??0));
            if(!$assignees) continue;
            $due_text=$due_at->format('d M Y · g:i A');
            $message='Task is overdue and still not completed.';
            $sent=0;$failed=0;
            foreach($assignees as $eid){
                $delivery=$this->attempt_overdue_reminder_delivery($task,$date,$overdue_cfg,$eid,$scheduled,$due_at,$now,$message);
                if(($delivery['state']??'')==='sent') $sent++;
                elseif(($delivery['state']??'')==='failed') $failed++;
            }
            if($sent) $this->log_activity('task_overdue_reminder_sent','After-due reminder sent to '.$sent.' Assignee'.($sent===1?'':'s').' · every '.max(1,(int)$overdue_cfg->interval_value).' '.$overdue_cfg->interval_unit,$task->workspace_id,$task->work_area_id,$task->id,$date);
            if($failed) $this->log_activity('task_overdue_reminder_failed','After-due reminder failed for '.$failed.' Assignee'.($failed===1?'':'s').' · retry scheduled',$task->workspace_id,$task->work_area_id,$task->id,$date);
        }
    }

    private function run_task_reminder_tick_for_site($blog_id){
        $blog_id=absint($blog_id); if(!$blog_id) return;
        $lock='bb_reminder_site_lock_'.$blog_id;
        if(get_site_transient($lock)) return;
        set_site_transient($lock,time(),55);
        $origin=(int)get_current_blog_id(); $switched=false;
        try{
            if(is_multisite() && $origin!==$blog_id){ switch_to_blog($blog_id); $switched=true; }
            $this->refresh_site_tables();
            $this->run_task_reminder_tick_for_current_site();
        }finally{
            if($switched) restore_current_blog();
            $this->refresh_site_tables();
            delete_site_transient($lock);
        }
    }

    public function task_reminder_tick(){
        update_site_option('bb_network_last_reminder_tick',time());
        // When the real server cron calls the main site's wp-cron.php, one event now scans
        // every active Workboard site. Subsite cron requests still safely process that subsite.
        if(is_multisite() && is_main_site()){
            if(get_site_transient('bb_network_reminder_tick_lock')) return;
            set_site_transient('bb_network_reminder_tick_lock',time(),55);
            try{
                $site_ids=get_sites(['fields'=>'ids','number'=>0,'deleted'=>0,'spam'=>0,'archived'=>0]);
                foreach((array)$site_ids as $blog_id) $this->run_task_reminder_tick_for_site((int)$blog_id);
            }finally{
                delete_site_transient('bb_network_reminder_tick_lock');
            }
            return;
        }
        $this->run_task_reminder_tick_for_site(get_current_blog_id());
    }

    public function hourly_notifications(){ return;
        global $wpdb;
        $this->refresh_recurring_instances();
        $now=current_time('timestamp'); $soon=$now+HOUR_IN_SECONDS;
        if(get_option('bb_notify_due_soon',1)){
            $one=$wpdb->get_results("SELECT * FROM {$this->tables['tasks']} WHERE is_template=0 AND template_id IS NULL AND assignee_id IS NOT NULL AND status IN ('Pending','In Progress') AND due_date IS NOT NULL");
            foreach($one as $t){
                $due=strtotime($t->due_date.' '.($t->due_time?:'23:59:59'));
                if($due && $due>=$now && $due<=$soon){
                    $this->notify_employee($t->assignee_id,'due_soon','Task due soon',$t->task_name.' is due at '.date_i18n('d M Y g:i A',$due),'due_one_'.$t->id.'_'.$t->due_date,$t->workspace_id,$t->id,$t->due_date);
                }
            }
            $templates=$wpdb->get_results("SELECT * FROM {$this->tables['tasks']} WHERE is_template=1 AND task_type='Recurring' AND assignee_id IS NOT NULL AND recurrence_status='active'");
            $today=current_time('Y-m-d');
            foreach($templates as $t){
                if(!in_array($today,$this->occurrence_dates($t,$today,$today),true)) continue;
                $due=strtotime($today.' '.($t->due_time?:'23:59:59'));
                if($due && $due>=$now && $due<=$soon){
                    $this->notify_employee($t->assignee_id,'due_soon','Recurring task due soon',$t->task_name.' is due at '.date_i18n('g:i A',$due),'due_rec_'.$t->id.'_'.$today,$t->workspace_id,$t->id,$today);
                }
            }
        }
    }


    /**
     * Network-aware morning pending-task digest.
     * Replaces the old Task Due Today / Task Overdue / Pending Task Summary messages.
     * One digest is sent per assignee, per site, per India calendar day.
     */
    public function morning_task_notifications(){
        if(is_multisite() && is_main_site()){
            $origin=get_current_blog_id();
            $sites=get_sites(['number'=>0,'deleted'=>0,'spam'=>0,'archived'=>0]);
            foreach((array)$sites as $site){
                $bid=(int)$site->blog_id;
                if(!$bid) continue;
                switch_to_blog($bid);
                $this->refresh_site_tables();
                $this->morning_task_notifications_for_current_site();
                restore_current_blog();
                $this->refresh_site_tables();
            }
            if(get_current_blog_id()!==$origin){
                switch_to_blog($origin);
                $this->refresh_site_tables();
            }
            return;
        }
        $this->morning_task_notifications_for_current_site();
    }

    private function morning_task_notifications_for_current_site($force_test=false){
        $result=['ok'=>true,'employees'=>0,'messages'=>0,'tasks'=>0,'failed'=>0,'errors'=>[],'reason'=>''];
        if(!get_option('bb_meta_whatsapp_enabled',0)) return ['ok'=>false,'employees'=>0,'messages'=>0,'tasks'=>0,'reason'=>'WhatsApp notifications are disabled for this site.'];
        if(!(int)get_option('bb_notify_morning_pending',1)) return ['ok'=>false,'employees'=>0,'messages'=>0,'tasks'=>0,'reason'=>'Morning Pending Tasks Reminder is disabled for this site.'];
        if(!(int)get_option('bb_meta_tpl_morning_enabled',1)) return ['ok'=>false,'employees'=>0,'messages'=>0,'tasks'=>0,'reason'=>'Morning Pending Tasks Reminder template is disabled.'];

        global $wpdb;
        $this->refresh_recurring_instances();
        $ist=new DateTimeZone('Asia/Kolkata');
        $today=(new DateTime('now',$ist))->format('Y-m-d');

        $employees=$wpdb->get_results("SELECT * FROM {$this->tables['employees']} WHERE status='active' AND mobile IS NOT NULL AND mobile<>'' ORDER BY id ASC");
        if(!$employees) return $result;

        $workspaces=[];
        foreach((array)$wpdb->get_results("SELECT id,name FROM {$this->tables['workspaces']}") as $r) $workspaces[(int)$r->id]=(string)$r->name;
        $areas=[];
        foreach((array)$wpdb->get_results("SELECT id,name FROM {$this->tables['work_areas']}") as $r) $areas[(int)$r->id]=(string)$r->name;

        // One-time tasks: only incomplete tasks with an actual due date <= today.
        $one_time=$wpdb->get_results($wpdb->prepare(
            "SELECT t.* FROM {$this->tables['tasks']} t LEFT JOIN {$this->tables['work_areas']} a ON a.id=t.work_area_id WHERE t.is_template=0 AND t.template_id IS NULL AND t.archived_at IS NULL AND t.due_date IS NOT NULL AND t.due_date<>'' AND t.due_date<=%s AND t.status IN ('Pending','In Progress','Overdue') AND (a.status IS NULL OR a.status NOT IN ('trash','archived')) ORDER BY t.due_date ASC,t.id ASC",
            $today
        ));

        // Non-daily recurring templates are represented virtually. Include only their
        // currently actionable occurrence when it is due today or retained overdue.
        $recurring=$wpdb->get_results("SELECT t.* FROM {$this->tables['tasks']} t LEFT JOIN {$this->tables['work_areas']} a ON a.id=t.work_area_id WHERE t.is_template=1 AND t.task_type='Recurring' AND t.archived_at IS NULL AND t.recurrence_status='active' AND t.frequency<>'Daily' AND (a.status IS NULL OR a.status NOT IN ('trash','archived'))");

        $by_employee=[];
        $append=function($task,$occurrence_date) use (&$by_employee,$workspaces,$areas){
            $ids=$this->task_assignee_employee_ids((int)$task->id,(int)($task->assignee_id??0));
            if(!$ids) return;
            $row=[
                'task'=>(string)$task->task_name,
                'date'=>(string)$occurrence_date,
                'workspace'=>$workspaces[(int)$task->workspace_id]??'Workspace',
                'area'=>$areas[(int)$task->work_area_id]??'Work Area',
            ];
            foreach($ids as $eid) $by_employee[(int)$eid][]=$row;
        };

        foreach((array)$one_time as $task) $append($task,(string)$task->due_date);
        foreach((array)$recurring as $task){
            $occ=$this->next_actionable_occurrence($task,$today);
            if(!$occ || $occ>$today) continue;
            // Recurring tasks qualify only when an operational occurrence date exists.
            $append($task,$occ);
        }

        foreach($employees as $emp){
            $eid=(int)$emp->id;
            $rows=$by_employee[$eid]??[];
            if(!$rows) continue;

            usort($rows,function($a,$b){
                $c=strcmp((string)$a['workspace'],(string)$b['workspace']); if($c!==0) return $c;
                $c=strcmp((string)$a['area'],(string)$b['area']); if($c!==0) return $c;
                $c=strcmp((string)$a['date'],(string)$b['date']); if($c!==0) return $c;
                return strcmp((string)$a['task'],(string)$b['task']);
            });

            // Keep the approved two-variable Meta template unchanged. Meta does not
            // allow line breaks inside a text parameter, so send one clean numbered
            // task list separated by pipes. Do not include Workspace/Work Area names.
            $parts=[];
            $n=1;
            foreach($rows as $r){
                $date=$r['date']?date('d/m/Y',strtotime($r['date'])):'-';
                $task_name=trim(wp_strip_all_tags((string)$r['task']));
                $task_name=preg_replace('/[\t\r\n]+/u',' ',$task_name);
                $task_name=preg_replace('/ {2,}/u',' ',$task_name);
                $parts[]=$n.'. '.$task_name.' - ('.$date.')';
                $n++;
            }
            $message=trim(implode(' | ',$parts));
            if($message==='') continue;

            // Keep a single daily digest. The notification source key also protects
            // against a subsite cron and the main-network cron firing on the same day.
            $result['employees']++;
            $result['tasks']+=count($rows);
            $source=$force_test
                ? 'morning_pending_test_'.$eid.'_'.$today.'_'.current_time('timestamp').'_'.wp_rand(1000,9999)
                : 'morning_pending_'.$eid.'_'.$today;
            $notification_saved=$this->notify_employee($eid,'morning_pending','Pending Tasks Reminder',$message,$source,0,0,$today);
            $wa=$this->last_whatsapp_result;
            if(is_array($wa) && !empty($wa['ok'])){
                $result['messages']++;
            } elseif(is_array($wa)) {
                $result['failed']++;
                $reason=trim((string)($wa['message']??'WhatsApp send failed')) ?: 'WhatsApp send failed';
                $result['errors'][]=(trim((string)$emp->name) ?: ('Employee '.$eid)).': '.$reason;
            } elseif(!$notification_saved) {
                $result['failed']++;
                $result['errors'][]=(trim((string)$emp->name) ?: ('Employee '.$eid)).': internal notification could not be saved.';
            } else {
                $result['failed']++;
                $result['errors'][]=(trim((string)$emp->name) ?: ('Employee '.$eid)).': WhatsApp send was not attempted.';
            }
        }
        return $result;
    }

    // Kept as a compatibility alias for sites that still have a stale cron callback queued.
    public function daily_task_summary(){ $this->morning_task_notifications(); }

    public function ajax_test_morning_pending_digest(){
        // Use the plugin's standard AJAX verifier. v4.13.21 accidentally called
        // a non-existent check_ajax() method, which caused an HTTP 500 and the
        // generic "Test failed" message before any task evaluation could run.
        $this->verify();
        if(!current_user_can('manage_options')) wp_send_json_error(['message'=>'You do not have permission to run this test.'],403);
        $result=$this->morning_task_notifications_for_current_site(true);
        if(empty($result['ok'])) wp_send_json_error(['message'=>$result['reason']?:'Morning Pending Tasks Reminder test could not run.']);
        if(empty($result['employees'])) wp_send_json_success(['message'=>'Test completed. No assignee currently has qualifying pending tasks.']);
        $sent=(int)($result['messages']??0);
        $failed=(int)($result['failed']??0);
        $base=sprintf('Test completed: %d WhatsApp message(s) accepted by Meta for %d assignee(s), covering %d task(s).',$sent,(int)$result['employees'],(int)$result['tasks']);
        if($failed>0){
            $errors=array_values(array_unique(array_filter((array)($result['errors']??[]))));
            $detail=$errors ? ' Failed: '.implode(' | ',array_slice($errors,0,5)) : sprintf(' %d send(s) failed.',$failed);
            wp_send_json_error(['message'=>$base.$detail,'sent'=>$sent,'failed'=>$failed,'errors'=>$errors]);
        }
        wp_send_json_success(['message'=>$base,'sent'=>$sent,'failed'=>0]);
    }

    private function current_notifications(){
        global $wpdb;
        $emp=$this->current_employee();
        if($emp) return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tables['notifications']} WHERE employee_id=%d OR wp_user_id=%d ORDER BY id DESC LIMIT 100",$emp->id,get_current_user_id()));
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tables['notifications']} WHERE wp_user_id=%d ORDER BY id DESC LIMIT 100",get_current_user_id()));
    }

    private function task_attachments_state($allowed=null){
        global $wpdb;
        $rows=$wpdb->get_results("SELECT a.*,p.post_title file_name,p.guid file_url,p.post_mime_type,u.display_name uploaded_by_name,t.workspace_id
            FROM {$this->tables['attachments']} a
            JOIN {$wpdb->posts} p ON p.ID=a.attachment_id
            LEFT JOIN {$wpdb->users} u ON u.ID=a.uploaded_by
            LEFT JOIN {$this->tables['tasks']} t ON t.id=a.task_id
            ORDER BY a.id DESC");
        if($allowed!==null) $rows=array_values(array_filter($rows,fn($r)=>in_array((int)$r->workspace_id,$allowed,true)));
        foreach($rows as $r){
            $url=wp_get_attachment_url($r->attachment_id); if($url) $r->file_url=$url;
            $r->thumb_url=wp_attachment_is_image($r->attachment_id)?wp_get_attachment_image_url($r->attachment_id,'thumbnail'):'';
        }
        return $rows;
    }

    private function refresh_recurring_instances(){
        // Lean recurrence engine: future occurrences are never inserted into the database.
        // Only a compact history row is stored when an occurrence becomes due or is completed early.
        global $wpdb;
        $templates=$wpdb->get_results("SELECT t.* FROM {$this->tables['tasks']} t LEFT JOIN {$this->tables['work_areas']} a ON a.id=t.work_area_id WHERE t.is_template=1 AND t.task_type='Recurring' AND (a.status IS NULL OR a.status NOT IN ('trash','archived'))");
        foreach($templates as $tpl) $this->sync_due_history($tpl);

        // One-time tasks continue to live as individual task rows.
        $now=current_time('timestamp');
        $open=$wpdb->get_results("SELECT t.id,t.due_date,t.due_time,t.status FROM {$this->tables['tasks']} t LEFT JOIN {$this->tables['work_areas']} a ON a.id=t.work_area_id WHERE t.is_template=0 AND t.template_id IS NULL AND t.status IN ('Pending','In Progress','Overdue') AND t.due_date IS NOT NULL AND (a.status IS NULL OR a.status NOT IN ('trash','archived'))");
        foreach($open as $t){
            $due=strtotime($t->due_date.' '.($t->due_time ?: '23:59:59'));
            $new_status=($due && $due<$now)?'Overdue':(($t->status==='In Progress')?'In Progress':'Pending');
            if($new_status!==$t->status){
                $wpdb->update($this->tables['tasks'],['status'=>$new_status,'updated_at'=>current_time('mysql')],['id'=>$t->id]);
                // v4.11.51: overdue WhatsApp reminders are sent in the 09:15 IST morning batch,
                // rather than immediately when a task crosses its due date/time.
            }
        }

        $this->auto_focus_due_or_overdue($templates);
    }

    private function auto_focus_due_or_overdue($templates=[]){
        // Auto-Focus the currently actionable due occurrence once when it is due today or overdue.
        // Daily recurring tasks are explicitly excluded. Auto metadata is keyed to the actual
        // due/occurrence date, so a manual removal is remembered for that occurrence and a page
        // refresh will not force the task back into Focus.
        global $wpdb;
        $today=current_time('Y-m-d');
        $now=current_time('mysql');

        // One-time tasks: anything due today or earlier is eligible. focus_auto_date stores the
        // task's due date (not the day it was processed), which makes the manual override durable
        // for the same overdue occurrence until the due date itself changes.
        $one_time=$wpdb->get_results($wpdb->prepare(
            "SELECT t.id,t.due_date,t.focus_auto_date,t.focus_manual_override_date FROM {$this->tables['tasks']} t LEFT JOIN {$this->tables['work_areas']} a ON a.id=t.work_area_id WHERE t.is_template=0 AND t.template_id IS NULL AND t.archived_at IS NULL AND t.due_date IS NOT NULL AND t.due_date<=%s AND t.status NOT IN ('Completed','Cancelled') AND t.is_focus=0 AND (a.status IS NULL OR a.status NOT IN ('trash','archived'))",
            $today
        ));
        foreach($one_time as $t){
            $occurrence=(string)$t->due_date;
            if($occurrence==='') continue;
            if((string)($t->focus_auto_date??'')===$occurrence) continue;
            if((string)($t->focus_manual_override_date??'')===$occurrence) continue;
            $wpdb->update($this->tables['tasks'],[
                'is_focus'=>1,
                'focus_auto_date'=>$occurrence,
                'focus_manual_override_date'=>null,
                'updated_at'=>$now
            ],['id'=>(int)$t->id]);
        }

        if(!$templates) $templates=$wpdb->get_results("SELECT t.* FROM {$this->tables['tasks']} t LEFT JOIN {$this->tables['work_areas']} a ON a.id=t.work_area_id WHERE t.is_template=1 AND t.task_type='Recurring' AND (a.status IS NULL OR a.status NOT IN ('trash','archived'))");
        foreach($templates as $tpl){
            if(strcasecmp((string)$tpl->frequency,'Daily')===0){
                // Daily recurrence may only be focused manually. Clear only Focus that was
                // explicitly auto-generated; a manual Focus has no focus_auto_date.
                if((string)($tpl->focus_auto_date??'')!==''){
                    $wpdb->update($this->tables['tasks'],[
                        'is_focus'=>0,
                        'focus_auto_date'=>null,
                        'focus_manual_override_date'=>null,
                        'updated_at'=>$now
                    ],['id'=>(int)$tpl->id]);
                    $tpl->is_focus=0; $tpl->focus_auto_date=null; $tpl->focus_manual_override_date=null;
                }
                continue;
            }
            if(!empty($tpl->archived_at) || ($tpl->recurrence_status??'active')!=='active') continue;

            // next_actionable_occurrence() already gives today's occurrence precedence, otherwise
            // it can return the retained overdue occurrence according to the recurrence policy.
            $occurrence=$this->next_actionable_occurrence($tpl,$today);
            if(!$occurrence || $occurrence>$today) continue;
            if((int)($tpl->is_focus??0)===1) continue;
            if((string)($tpl->focus_auto_date??'')===$occurrence) continue;
            if((string)($tpl->focus_manual_override_date??'')===$occurrence) continue;

            $wpdb->update($this->tables['tasks'],[
                'is_focus'=>1,
                'focus_auto_date'=>$occurrence,
                'focus_manual_override_date'=>null,
                'updated_at'=>$now
            ],['id'=>(int)$tpl->id]);
            // Keep this response aligned with the stored row.
            $tpl->is_focus=1;
            $tpl->focus_auto_date=$occurrence;
            $tpl->focus_manual_override_date=null;
        }
    }

    private function recurring_overdue_policy($tpl){
        $p=sanitize_key($tpl->overdue_policy??'until_next');
        return $p==='keep'?'keep':'until_next';
    }

    private function next_effective_occurrence($date,$tpl){
        $next=$this->next_occurrence($date,$tpl); $guard=0;
        while($next && $guard<1000){
            if($tpl->end_date && $next>$tpl->end_date) return null;
            if(!$this->is_exception($tpl->id,$next)) return $next;
            $next=$this->next_occurrence($next,$tpl); $guard++;
        }
        return null;
    }

    private function recurring_overdue_visible($tpl,$occurrence_date,$today){
        if(!$occurrence_date || !$today || $occurrence_date>=$today) return false;
        // Daily recurrence always resets operationally each day. Missed rows stay in history only.
        if(($tpl->frequency??'Daily')==='Daily') return false;
        if($this->recurring_overdue_policy($tpl)==='keep') return true;
        // Default: keep the missed occurrence visible only until the next actual recurrence arrives.
        $next=$this->next_effective_occurrence($occurrence_date,$tpl);
        return !$next || $today<$next;
    }

    // v4.13.76: Recurrence must always stay on the schedule anchored to start_date.
    // If a recurring task is created after its start date, begin from the latest REAL scheduled
    // occurrence on/before the creation date. Never treat created_at itself as an occurrence.
    private function recurrence_effective_start($tpl){
        if(empty($tpl->start_date)) return null;
        $start=(string)$tpl->start_date;
        $created=!empty($tpl->created_at)?substr((string)$tpl->created_at,0,10):$start;
        if(!$created || $created<=$start) return $start;
        $date=$start; $previous=$start; $guard=0;
        while($date && $date<=$created && $guard<7000){
            $previous=$date;
            $next=$this->next_occurrence($date,$tpl);
            if(!$next || $next<=$date) break;
            $date=$next; $guard++;
        }
        return $previous ?: $start;
    }

    private function scheduled_occurrence_set($tpl,$from,$to){
        $set=[]; if(!$from || !$to || $from>$to) return $set;
        $date=$from; $guard=0;
        while($date && $date<=$to && $guard<7000){
            if(!$tpl->end_date || $date<=$tpl->end_date) $set[$date]=true;
            if($tpl->end_date && $date>=$tpl->end_date) break;
            $next=$this->next_occurrence($date,$tpl);
            if(!$next || $next<=$date) break;
            $date=$next; $guard++;
        }
        return $set;
    }

    private function cleanup_invalid_auto_recurring_history($tpl,$valid_dates,$from,$to){
        // Remove only unresolved auto-created Missed rows that are not on the recurrence schedule.
        // This repairs legacy phantom rows such as a Yearly 19-Aug task incorrectly created on 08-Sep.
        global $wpdb;
        if(!$from || !$to || $from>$to) return;
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT id,occurrence_date,status,result FROM {$this->tables['history']} WHERE task_id=%d AND occurrence_date>=%s AND occurrence_date<=%s",
            $tpl->id,$from,$to
        ));
        foreach($rows as $h){
            $date=(string)$h->occurrence_date;
            if(isset($valid_dates[$date])) continue;
            if((string)$h->status==='Completed' || (string)$h->status==='Archived') continue;
            if((string)$h->result!=='' && (string)$h->result!=='Missed') continue;
            $wpdb->delete($this->tables['history'],['id'=>(int)$h->id]);
            $wpdb->delete($this->tables['subtask_progress'],['task_id'=>(int)$tpl->id,'occurrence_key'=>$date]);
            $wpdb->delete($this->tables['task_remarks'],['task_id'=>(int)$tpl->id,'occurrence_key'=>$date]);
        }
    }

    private function sync_due_history($tpl){
        global $wpdb;
        if(!$tpl->start_date) return;
        if(($tpl->recurrence_status??'active')==='paused') return;
        $today=current_time('Y-m-d');
        $now=current_time('timestamp');
        $start=$this->recurrence_effective_start($tpl);
        if(!$start) return;
        if($tpl->end_date && $start>$tpl->end_date) return;

        // Build from the recurrence anchor, never from MAX(history). A malformed legacy history
        // date must not shift all future Yearly/Monthly/Weekly occurrences.
        $valid_dates=$this->scheduled_occurrence_set($tpl,$start,$today);
        $this->cleanup_invalid_auto_recurring_history($tpl,$valid_dates,$start,$today);

        foreach(array_keys($valid_dates) as $date){
            if($this->is_exception($tpl->id,$date)) continue;
            $exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['history']} WHERE task_id=%d AND occurrence_date=%s LIMIT 1",$tpl->id,$date));
            if($exists) continue;
            $due_ts=strtotime($date.' '.($tpl->due_time ?: '23:59:59'));
            if($due_ts && $due_ts<$now){
                $wpdb->insert($this->tables['history'],[
                    'task_id'=>$tpl->id,'occurrence_date'=>$date,'due_date'=>$date,'due_time'=>$tpl->due_time,
                    'assignee_id'=>$tpl->assignee_id,'workspace_id'=>$tpl->workspace_id,'work_area_id'=>$tpl->work_area_id,
                    'status'=>'Overdue','result'=>'Missed','score_points'=>0,'updated_at'=>current_time('mysql')
                ]);
                if(($tpl->frequency??'Daily')!=='Daily' && get_option('bb_notify_overdue',1) && $tpl->assignee_id){
                    $this->notify_employee($tpl->assignee_id,'overdue','Task overdue',$tpl->task_name.' was due on '.date_i18n('d M Y',strtotime($date)),'over_rec_'.$tpl->id.'_'.$date,$tpl->workspace_id,$tpl->id,$date);
                    $this->notify_work_area_head($tpl->work_area_id,'overdue','Task overdue',$tpl->task_name.' is overdue','over_rec_'.$tpl->id.'_'.$date,$tpl->workspace_id,$tpl->id,$date,$tpl->assignee_id);
                }
            }
        }
    }

    private function occurrence_dates($tpl,$from,$to){
        $out=[]; if(!$tpl->start_date || $from>$to) return $out;
        if(($tpl->recurrence_status??'active')==='paused') return $out;
        $date=$tpl->start_date; $guard=0;
        while($date && $date<$from && $guard<5000){ $date=$this->next_occurrence($date,$tpl); $guard++; }
        while($date && $date<=$to && $guard<7000){
            if((!$tpl->end_date || $date<=$tpl->end_date) && !$this->is_exception($tpl->id,$date)) $out[]=$date;
            if($tpl->end_date && $date>=$tpl->end_date) break;
            $date=$this->next_occurrence($date,$tpl); $guard++;
        }
        return $out;
    }

    // v4.16.80: no-query recurrence helpers used by Pending Tasks. The previous
    // implementation called history/exception SQL repeatedly while walking each recurrence date.
    private function next_effective_occurrence_preloaded($date,$tpl,$exception_dates){
        $next=$this->next_occurrence($date,$tpl); $guard=0;
        while($next && $guard<1000){
            if($tpl->end_date && $next>$tpl->end_date) return null;
            if(empty($exception_dates[$next])) return $next;
            $next=$this->next_occurrence($next,$tpl); $guard++;
        }
        return null;
    }

    private function recurring_overdue_visible_preloaded($tpl,$occurrence_date,$today,$exception_dates){
        if(!$occurrence_date || !$today || $occurrence_date>=$today) return false;
        if(($tpl->frequency??'Daily')==='Daily') return false;
        if($this->recurring_overdue_policy($tpl)==='keep') return true;
        $next=$this->next_effective_occurrence_preloaded($occurrence_date,$tpl,$exception_dates);
        return !$next || $today<$next;
    }

    private function next_recurring_due_date_preloaded($tpl,$current_date,$today,$exception_dates){
        if(!$current_date) return null;
        if($current_date >= $today) return $current_date;
        $next=$this->next_effective_occurrence_preloaded($current_date,$tpl,$exception_dates); $guard=0;
        while($next && $next<$today && $guard<2000){
            $next=$this->next_effective_occurrence_preloaded($next,$tpl,$exception_dates); $guard++;
        }
        return $next ?: null;
    }

    private function history_as_task_preloaded($h,$tpl,$workspace_name,$work_area_name,$work_area_color,$assignee_name,$exception_dates,$today){
        $o=clone $tpl;
        $o->id='r_'.$tpl->id.'_'.$h->occurrence_date;
        $o->is_template=0; $o->template_id=$tpl->id; $o->occurrence_date=$h->occurrence_date; $o->due_date=$h->due_date;
        $o->status=$h->status; $o->stage_name=(string)($h->stage_name??''); $o->completion_result=$h->result??''; $o->completed_at=$h->completed_at??null;
        $o->next_due_date=($h->occurrence_date && $h->occurrence_date<$today && !in_array((string)$h->status,['Completed','Archived'],true))
            ? $this->next_recurring_due_date_preloaded($tpl,$h->occurrence_date,$today,$exception_dates) : null;
        $o->is_focus=$this->recurring_occurrence_focus($tpl,$h->occurrence_date);
        $o->workspace_name=$workspace_name; $o->work_area_name=$work_area_name; $o->work_area_color=$work_area_color; $o->assignee_name=$assignee_name;
        return $o;
    }

    private function next_actionable_occurrence_preloaded($tpl,$from,$history_status,$exception_dates){
        if(!$tpl->start_date || ($tpl->recurrence_status??'active')==='paused') return null;
        $date=$this->recurrence_effective_start($tpl) ?: $tpl->start_date; $guard=0;
        // Pending Tasks adds retained overdue occurrences directly from history. Here we only
        // locate today's/future occurrence, so completed historical rows never need to be loaded.
        while($date && $date<$from && $guard<7000){
            if($tpl->end_date && $date>$tpl->end_date) return null;
            $date=$this->next_occurrence($date,$tpl); $guard++;
        }
        while($date && $guard<9000){
            if($tpl->end_date && $date>$tpl->end_date) return null;
            if(empty($exception_dates[$date])){
                $status=$history_status[$date]??'';
                if(!in_array($status,['Completed','Archived'],true)) return $date;
            }
            $date=$this->next_occurrence($date,$tpl); $guard++;
        }
        return null;
    }

    private function next_actionable_occurrence($tpl,$from){
        global $wpdb;
        if(!$tpl->start_date) return null;
        if(($tpl->recurrence_status??'active')==='paused') return null;
        try{ new DateTime($from); }catch(Exception $e){ return null; }

        $date=$this->recurrence_effective_start($tpl) ?: $tpl->start_date; $guard=0; $visible_overdue=null;
        while($date && $date<=$from && $guard<7000){
            if($tpl->end_date && $date>$tpl->end_date) return null;
            if(!$this->is_exception($tpl->id,$date)){
                $h=$wpdb->get_row($wpdb->prepare("SELECT status FROM {$this->tables['history']} WHERE task_id=%d AND occurrence_date=%s LIMIT 1",$tpl->id,$date));
                if(!$h || $h->status!=='Completed'){
                    // A recurrence due today always takes precedence as the fresh current occurrence.
                    // Older unresolved rows are already added separately from history when policy says to keep them.
                    if($date===$from) return $date;
                    if($date<$from && $this->recurring_overdue_visible($tpl,$date,$from) && $visible_overdue===null) $visible_overdue=$date;
                }
            }
            $date=$this->next_occurrence($date,$tpl); $guard++;
        }
        if($visible_overdue!==null) return $visible_overdue;

        // Nothing actionable is being retained from the current cycle: expose the next future occurrence.
        while($date && $guard<9000){
            if($tpl->end_date && $date>$tpl->end_date) return null;
            if(!$this->is_exception($tpl->id,$date)){
                $h=$wpdb->get_row($wpdb->prepare("SELECT status FROM {$this->tables['history']} WHERE task_id=%d AND occurrence_date=%s LIMIT 1",$tpl->id,$date));
                if(!$h || $h->status!=='Completed') return $date;
            }
            $date=$this->next_occurrence($date,$tpl); $guard++;
        }
        return null;
    }

    private function next_recurring_due_date($tpl,$current_date,$today=''){
        if(!$current_date) return null;
        $today=$today ?: current_time('Y-m-d');
        if($current_date >= $today) return $current_date;
        $next=$this->next_effective_occurrence($current_date,$tpl); $guard=0;
        while($next && $next < $today && $guard < 2000){
            $next=$this->next_effective_occurrence($next,$tpl);
            $guard++;
        }
        return $next ?: null;
    }

    private function recurring_occurrence_focus($tpl,$occurrence_date){
        // Manual Focus keeps the existing template-level behaviour. Auto Focus is scoped
        // to the occurrence that triggered it so other recurring occurrences do not light up.
        $auto=(string)($tpl->focus_auto_date??'');
        if($auto!=='') return ((int)($tpl->is_focus??0)===1 && $auto===(string)$occurrence_date && (string)($tpl->focus_manual_override_date??'')!==$auto) ? 1 : 0;
        return (int)($tpl->is_focus??0);
    }

    private function history_as_task($h,$tpl,$workspace_name='',$work_area_name='',$work_area_color='',$assignee_name=''){
        $o=clone $tpl;
        $o->id='r_'.$tpl->id.'_'.$h->occurrence_date;
        $o->is_template=0; $o->template_id=$tpl->id; $o->occurrence_date=$h->occurrence_date; $o->due_date=$h->due_date;
        $o->status=$h->status; $o->stage_name=(string)($h->stage_name??''); $o->completion_result=$h->result; $o->completed_at=$h->completed_at;
        $o->next_due_date=($h->occurrence_date && $h->occurrence_date<current_time('Y-m-d') && !in_array((string)$h->status,['Completed','Archived'],true)) ? $this->next_recurring_due_date($tpl,$h->occurrence_date) : null;
        $o->is_focus=$this->recurring_occurrence_focus($tpl,$h->occurrence_date);
        $o->workspace_name=$workspace_name; $o->work_area_name=$work_area_name; $o->work_area_color=$work_area_color; $o->assignee_name=$assignee_name;
        return $o;
    }

    private function virtual_task($tpl,$date,$workspace_name='',$work_area_name='',$work_area_color='',$assignee_name=''){
        $o=clone $tpl;
        $o->id='r_'.$tpl->id.'_'.$date; $o->is_template=0; $o->template_id=$tpl->id; $o->occurrence_date=$date; $o->due_date=$date;
        $o->status='Pending'; $o->stage_name=''; $o->completion_result=''; $o->completed_at=null;
        $o->next_due_date=($date && $date<current_time('Y-m-d')) ? $this->next_recurring_due_date($tpl,$date) : null;
        $o->is_focus=$this->recurring_occurrence_focus($tpl,$date);
        $o->workspace_name=$workspace_name; $o->work_area_name=$work_area_name; $o->work_area_color=$work_area_color; $o->assignee_name=$assignee_name;
        return $o;
    }

    private function next_occurrence($date,$tpl){
        try{$d=new DateTime($date);}catch(Exception $e){return null;}
        $freq=$tpl->frequency ?: 'Daily';
        switch($freq){
            case 'Daily': $d->modify('+1 day'); break;
            case 'Weekly': $d->modify('+1 week'); break;
            case 'Fortnightly': $d->modify('+2 weeks'); break;
            case 'Monthly': $d->modify('+1 month'); break;
            case 'Quarterly': $d->modify('+3 months'); break;
            case 'Yearly': $d->modify('+1 year'); break;
            case 'Custom Interval':
                $n=max(1,(int)$tpl->interval_value); $unit=strtolower($tpl->interval_unit ?: 'days');
                if(!in_array($unit,['days','weeks','months','years'],true)) $unit='days';
                $d->modify('+'.$n.' '.$unit); break;
            default: return null;
        }
        return $d->format('Y-m-d');
    }

    public function export_visible_tasks(){
        if(!is_user_logged_in()) wp_die('Login required'); check_admin_referer('bb_export_visible_tasks'); global $wpdb;
        $ids=array_filter(array_map('absint',explode(',',sanitize_text_field($_GET['ids']??'')))); if(!$ids)wp_die('No tasks selected for export');
        $rows=[]; foreach($ids as $id){$t=$wpdb->get_row($wpdb->prepare("SELECT t.*,w.name workspace_name,a.name work_area_name,e.name assignee_name FROM {$this->tables['tasks']} t LEFT JOIN {$this->tables['workspaces']} w ON w.id=t.workspace_id LEFT JOIN {$this->tables['work_areas']} a ON a.id=t.work_area_id LEFT JOIN {$this->tables['employees']} e ON e.id=t.assignee_id WHERE t.id=%d",$id));if($t)$rows[]=$t;}
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');header('Content-Disposition: attachment; filename="business-board-visible-tasks-'.date('Y-m-d').'.xls"');echo '<style>html,body,table,thead,tbody,tr,th,td{font-family:Arial,sans-serif !important;}</style>';echo "<table border=1><tr><th>Workspace</th><th>Work Area</th><th>Task</th><th>Status</th><th>Due Date</th><th>Priority</th><th>Assignee</th><th>Labels</th><th>Recurrence</th><th>Latest Update</th><th>Sub-task Progress</th><th>Completed By</th><th>Completed On</th><th>Created On</th></tr>";foreach($rows as $t){$latest=$wpdb->get_var($wpdb->prepare("SELECT remark FROM {$this->tables['task_remarks']} WHERE task_id=%d ORDER BY id DESC LIMIT 1",$t->id));$total=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['checklists']} WHERE task_id=%d",$t->id));$done=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['checklists']} WHERE task_id=%d AND is_done=1",$t->id));$cb=$t->completed_by_employee_id?$wpdb->get_var($wpdb->prepare("SELECT name FROM {$this->tables['employees']} WHERE id=%d",$t->completed_by_employee_id)):'';$vals=[$t->workspace_name,$t->work_area_name,$t->task_name,$t->status,$t->due_date,$t->priority,$t->assignee_name,$t->labels,$t->task_type==='Recurring'?$t->frequency:'One Time',$latest,"$done/$total",$cb,$t->completed_at,$t->created_at];echo '<tr>'.implode('',array_map(fn($v)=>'<td>'.esc_html($v).'</td>',$vals)).'</tr>';}echo '</table>';exit;
    }
    public function export_activity_updates(){
        if(!is_user_logged_in())wp_die('Login required');check_admin_referer('bb_export_activity_updates');global $wpdb;$allowed=['task_in_progress','task_pending','task_stage_changed','task_completed','task_reopened','subtask_completed','subtask_reopened'];$ph=implode(',',array_fill(0,count($allowed),'%s'));$sql="SELECT l.created_at,w.name workspace_name,a.name work_area_name,t.task_name,l.action,l.details,COALESCE(NULLIF(e.name,''),(SELECT NULLIF(e2.name,'') FROM {$this->tables['employees']} e2 WHERE e2.wp_user_id=l.user_id ORDER BY e2.id ASC LIMIT 1),(SELECT NULLIF(e3.name,'') FROM {$this->tables['employees']} e3 WHERE u.user_email IS NOT NULL AND LOWER(e3.email)=LOWER(u.user_email) ORDER BY e3.id ASC LIMIT 1),CASE WHEN u.display_name<>u.user_email THEN NULLIF(u.display_name,'') ELSE NULL END,'System') updated_by FROM {$this->tables['activity']} l LEFT JOIN {$this->tables['workspaces']} w ON w.id=l.workspace_id LEFT JOIN {$this->tables['work_areas']} a ON a.id=l.work_area_id LEFT JOIN {$this->tables['tasks']} t ON t.id=l.task_id LEFT JOIN {$this->tables['employees']} e ON e.id=l.employee_id LEFT JOIN {$wpdb->users} u ON u.ID=l.user_id WHERE l.action IN ($ph) ORDER BY l.id DESC";$rows=$wpdb->get_results($wpdb->prepare($sql,...$allowed));header('Content-Type: application/vnd.ms-excel; charset=UTF-8');header('Content-Disposition: attachment; filename="business-board-task-updates-'.wp_date('Y-m-d',null,new DateTimeZone('Asia/Kolkata')).'.xls"');echo '<style>html,body,table,thead,tbody,tr,th,td{font-family:Arial,sans-serif !important;}</style><table border=1><tr><th>Date & Time</th><th>Workspace</th><th>Work Area</th><th>Task</th><th>Activity</th><th>Updated By</th></tr>';foreach($rows as $r){$detail=trim((string)$r->details);if($r->action==='subtask_completed'){$activity='Sub Task — '.($detail!==''?$detail:'Untitled').' — Completed';}elseif($r->action==='subtask_reopened'){$activity='Sub Task — '.($detail!==''?$detail:'Untitled').' — Reopened';}elseif($r->action==='task_completed'){$detail=preg_replace('/\s+(?:-|·|–|—)\s+/u',' — ',$detail);$activity='Task Completed'.($detail!==''?' — '.$detail:'');}elseif($r->action==='task_reopened'){$detail=preg_replace('/\s+(?:-|·|–|—)\s+/u',' — ',$detail);$activity='Task Reopened'.($detail!==''?' — '.$detail:'');}else{$activity='Status Updated'.($detail!==''?' — '.$detail:'');}$vals=[$this->activity_time_ist($r->created_at),$r->workspace_name,$r->work_area_name,$r->task_name,$activity,$r->updated_by];echo '<tr>'.implode('',array_map(fn($v)=>'<td>'.esc_html($v).'</td>',$vals)).'</tr>';}echo '</table>';exit;
    }

    private function math_pdf_ascii($text){
        $text=wp_strip_all_tags((string)$text);
        $text=str_replace(["\r","\n","\t"],' ',$text);
        $text=preg_replace('/\s+/u',' ',$text);
        if(function_exists('iconv')){
            $converted=@iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$text);
            if($converted!==false) $text=$converted;
        }
        return trim((string)$text);
    }

    private function math_pdf_escape($text){
        return str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$this->math_pdf_ascii($text));
    }

    private function math_pdf_wrap($text,$max_chars){
        $text=$this->math_pdf_ascii($text);
        if($text==='') return [''];
        $words=preg_split('/\s+/',$text,-1,PREG_SPLIT_NO_EMPTY);
        $lines=[];$line='';
        foreach($words as $word){
            while(strlen($word)>$max_chars){
                if($line!==''){$lines[]=$line;$line='';}
                $lines[]=substr($word,0,$max_chars);
                $word=substr($word,$max_chars);
            }
            $candidate=$line===''?$word:$line.' '.$word;
            if(strlen($candidate)>$max_chars && $line!==''){$lines[]=$line;$line=$word;}else{$line=$candidate;}
        }
        if($line!=='')$lines[]=$line;
        return $lines?:[''];
    }

    private function math_pdf_hex_rgb($hex){
        $hex=ltrim((string)$hex,'#');
        if(strlen($hex)===3)$hex=$hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        if(!preg_match('/^[0-9a-fA-F]{6}$/',$hex))$hex='0ea5e9';
        return [hexdec(substr($hex,0,2))/255,hexdec(substr($hex,2,2))/255,hexdec(substr($hex,4,2))/255];
    }

    private function math_pdf_build($mathspace,$area,$entries){
        $W=595.28;$H=841.89;$margin=36.0;$right=$W-$margin;
        $show_date=(int)($area->show_date??0)===1;
        $show_contact=(int)($area->show_contact_number??0)===1;
        $total=0.0;foreach($entries as $e)$total+=(float)$e->amount;
        $pages=[];$content='';$y=0;
        $text=function($x,$y,$txt,$size=10,$bold=false)use(&$content){$f=$bold?'F2':'F1';$content.="BT /$f $size Tf 1 0 0 1 ".number_format($x,2,'.','')." ".number_format($y,2,'.','')." Tm (".$this->math_pdf_escape($txt).") Tj ET\n";};
        $line=function($x1,$y1,$x2,$y2,$r=.86,$g=.89,$b=.93)use(&$content){$content.=number_format($r,3,'.',' ').' '.number_format($g,3,'.',' ').' '.number_format($b,3,'.',' ')." RG 0.6 w ".number_format($x1,2,'.','')." ".number_format($y1,2,'.','')." m ".number_format($x2,2,'.','')." ".number_format($y2,2,'.','')." l S\n";};
        $fill=function($x,$y,$w,$h,$r,$g,$b)use(&$content){$content.=number_format($r,3,'.','').' '.number_format($g,3,'.','').' '.number_format($b,3,'.','')." rg ".number_format($x,2,'.','')." ".number_format($y,2,'.','')." ".number_format($w,2,'.','')." ".number_format($h,2,'.','')." re f\n";};
        $page_header=function()use(&$content,&$y,$W,$H,$margin,$right,$mathspace,$area,$total,$text,$line,$fill,$show_date,$show_contact){
            $content='';
            $fill($margin,$H-92,$W-2*$margin,56,.96,.96,.96);
            $fill($margin,$H-39,$W-2*$margin,1,0,0,0);
            $text($margin+12,$H-60,$area->name,18,true);
            $text($margin+12,$H-78,($mathspace->name?:'MathSpace').'  |  Total: '.number_format($total,2,'.',','),10,false);
            $y=$H-120;
            $text($margin,$y,'PARTICULAR',9,true);
            $x=$margin+270;
            if($show_date){$text($x,$y,'DATE',9,true);$x+=70;}
            if($show_contact){$text($x,$y,'CONTACT NUMBER',9,true);$x+=105;}
            $text($right-55,$y,'AMOUNT',9,true);
            $y-=9;$line($margin,$y,$right,$y,0,0,0);$y-=18;
        };
        $finish_page=function()use(&$pages,&$content){$pages[]=$content;};
        $page_header();
        if(!$entries){$text($margin,$y,'No entries yet',10,false);$y-=20;}
        foreach($entries as $e){
            $part=$this->math_pdf_ascii($e->particular??'');
            $lines=$this->math_pdf_wrap($part,46);
            $row_h=max(30,15*count($lines)+8);
            if($y-$row_h<55){$finish_page();$page_header();}
            $start_y=$y;
            foreach($lines as $i=>$ln)$text($margin,$start_y-14*$i,$ln,10,$i===0);
            $x=$margin+270;
            if($show_date){
                $d='';if(!empty($e->entry_date)){ $ts=strtotime((string)$e->entry_date);if($ts)$d=date('d/m/Y',$ts); }
                $text($x,$start_y,$d,9,false);$x+=70;
            }
            if($show_contact){$num=preg_replace('/\D+/','',(string)($e->number??''));$text($x,$start_y,$num,9,false);$x+=105;}
            $amt=number_format((float)($e->amount??0),2,'.',',');
            $text($right-3-strlen($amt)*5.2,$start_y,$amt,10,true);
            $y-=$row_h;$line($margin,$y+8,$right,$y+8);$y-=5;
        }
        $finish_page();

        $objects=[];
        $objects[1]='<< /Type /Catalog /Pages 2 0 R >>';
        $page_refs=[];$n=count($pages);
        for($i=0;$i<$n;$i++)$page_refs[]=(6+$i*2).' 0 R';
        $objects[2]='<< /Type /Pages /Count '.$n.' /Kids [ '.implode(' ',$page_refs).' ] >>';
        $objects[3]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        foreach($pages as $i=>$stream){
            $content_id=5+$i*2;$page_id=6+$i*2;
            $objects[$content_id]='<< /Length '.strlen($stream)." >>\nstream\n".$stream."endstream";
            $objects[$page_id]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.number_format($W,2,'.','').' '.number_format($H,2,'.','').'] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.$content_id.' 0 R >>';
        }
        ksort($objects);
        $pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";$offsets=[0];
        foreach($objects as $id=>$obj){$offsets[$id]=strlen($pdf);$pdf.=$id." 0 obj\n".$obj."\nendobj\n";}
        $xref=strlen($pdf);$max=max(array_keys($objects));
        $pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n";
        for($i=1;$i<=$max;$i++)$pdf.=sprintf('%010d 00000 n ',(int)($offsets[$i]??0))."\n";
        $pdf.="trailer\n<< /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
        return $pdf;
    }

    public function export_math_area_pdf(){
        if(!is_user_logged_in()) wp_die('Login required','Workboard',['response'=>403]);
        check_admin_referer('bb_export_nonce','nonce');
        global $wpdb;
        $area_id=absint($_GET['math_area_id']??0);
        if(!$area_id) wp_die('Math Area is required','Workboard',['response'=>400]);
        $area=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['math_areas']} WHERE id=%d LIMIT 1",$area_id));
        if(!$area) wp_die('Math Area not found','Workboard',['response'=>404]);
        if(!$this->math_area_permission($area_id,'view')) wp_die('You do not have access to this Math Area','Workboard',['response'=>403]);
        $mathspace=$wpdb->get_row($wpdb->prepare("SELECT id,name FROM {$this->tables['mathspaces']} WHERE id=%d AND status='active' LIMIT 1",$area->mathspace_id));
        if(!$mathspace) wp_die('MathSpace not found','Workboard',['response'=>404]);
        $entries=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tables['math_entries']} WHERE math_area_id=%d ORDER BY sort_order ASC,id ASC",$area_id));
        $pdf=$this->math_pdf_build($mathspace,$area,$entries);
        $safe=sanitize_file_name(($mathspace->name?:'mathspace').'-'.($area->name?:'math-area'));
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="'.$safe.'-'.date('Y-m-d').'.pdf"');
        header('Content-Length: '.strlen($pdf));
        echo $pdf;exit;
    }

    public function export_notespace_excel(){
        if(!is_user_logged_in()) wp_die('Login required','Workboard',['response'=>403]);
        check_admin_referer('bb_export_nonce','nonce');
        global $wpdb;
        $notespace_id=absint($_GET['notespace_id']??0);
        if(!$notespace_id) wp_die('Notespace is required','Workboard',['response'=>400]);
        $ns=$wpdb->get_row($wpdb->prepare("SELECT id,name FROM {$this->tables['notespaces']} WHERE id=%d AND status='active' LIMIT 1",$notespace_id));
        if(!$ns) wp_die('Notespace not found','Workboard',['response'=>404]);
        $allowed_area_ids=$this->allowed_note_area_ids();
        $area_filter='';
        if($allowed_area_ids!==null){
            $allowed_area_ids=array_values(array_unique(array_filter(array_map('intval',$allowed_area_ids))));
            if(!$allowed_area_ids) wp_die('You do not have access to any Note Area in this Notespace','Workboard',['response'=>403]);
            $visible_in_space=array_map('intval',$wpdb->get_col(
                "SELECT id FROM {$this->tables['note_areas']} WHERE notespace_id=".(int)$notespace_id." AND id IN (".implode(',',$allowed_area_ids).")"
            ));
            if(!$visible_in_space) wp_die('You do not have access to any Note Area in this Notespace','Workboard',['response'=>403]);
            $area_filter=' AND a.id IN ('.implode(',',$visible_in_space).')';
        }
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT a.name note_area_name,c.title,c.note_type,c.text_content,c.url,c.link_title,c.link_description,c.created_at,c.updated_at
             FROM {$this->tables['note_areas']} a
             LEFT JOIN {$this->tables['note_cards']} c ON c.note_area_id=a.id AND c.notespace_id=a.notespace_id
             WHERE a.notespace_id=%d{$area_filter}
             ORDER BY a.sort_order ASC,a.id ASC,c.sort_order ASC,c.id ASC",
            $notespace_id
        ));
        $safe=sanitize_file_name($ns->name?:'notespace');
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$safe.'-notes-'.date('Y-m-d').'.xls"');
        echo "ï»¿";
        echo '<style>html,body,table,thead,tbody,tr,th,td{font-family:Arial,sans-serif !important;}</style><table border="1"><tr><th>Notespace</th><th>Note Area</th><th>Title</th><th>Type</th><th>Note</th><th>Link</th><th>Website Title</th><th>Website Description</th><th>Created On</th><th>Updated On</th></tr>';
        $has_cards=false;
        foreach($rows as $r){
            if($r->title===null && $r->note_type===null) continue;
            $has_cards=true;
            $vals=[
                $ns->name,$r->note_area_name,$r->title,
                strtolower((string)$r->note_type)==='link'?'Link':'Text',
                $r->text_content,$r->url,$r->link_title,$r->link_description,$r->created_at,$r->updated_at
            ];
            echo '<tr>';
            foreach($vals as $v) echo '<td style="vertical-align:top">'.nl2br(esc_html((string)$v)).'</td>';
            echo '</tr>';
        }
        if(!$has_cards) echo '<tr><td colspan="10">No notes in this Notespace</td></tr>';
        echo '</table>';
        exit;
    }

    public function ajax_front_login(){
        global $wpdb;
        $login=trim(sanitize_text_field($_POST['login']??''));
        $password=(string)($_POST['password']??'');
        if(!$login||!$password) wp_send_json_error(['message'=>'Login ID, email or user name and password are required']);

        // Workboard users may sign in using Login ID, email, or their displayed User Name.
        $emp=$wpdb->get_row($wpdb->prepare(
            "SELECT id,name,email,employee_code,status,wp_user_id FROM {$this->tables['employees']} WHERE LOWER(employee_code)=LOWER(%s) OR LOWER(email)=LOWER(%s) OR LOWER(name)=LOWER(%s) ORDER BY CASE WHEN LOWER(employee_code)=LOWER(%s) THEN 0 WHEN LOWER(email)=LOWER(%s) THEN 1 ELSE 2 END, id ASC LIMIT 1",
            $login,$login,$login,$login,$login
        ));
        if($emp){
            if(strtolower((string)$emp->status)==='inactive') wp_send_json_error(['message'=>'This user is inactive']);
            if(!$emp->wp_user_id) wp_send_json_error(['message'=>'Login is not configured for this user. Please ask the administrator to edit the user and set a password.']);
            $user=get_user_by('id',(int)$emp->wp_user_id);
            if(!$user) wp_send_json_error(['message'=>'Login account is missing for this user. Please ask the administrator to reset the password.']);
        } else {
            $party=$wpdb->get_row($wpdb->prepare("SELECT id,party_name,party_email,login_enabled,login_id,status,wp_user_id FROM {$this->tables['parties']} WHERE LOWER(login_id)=LOWER(%s) OR LOWER(party_email)=LOWER(%s) OR LOWER(party_name)=LOWER(%s) ORDER BY id ASC LIMIT 1",$login,$login,$login));
            if($party){ if((int)$party->login_enabled!==1 || strtolower((string)$party->status)==='inactive') wp_send_json_error(['message'=>'Party login is disabled']); $user=$party->wp_user_id?get_user_by('id',(int)$party->wp_user_id):false; }
            else { $user=get_user_by('email',$login); if(!$user) $user=get_user_by('login',$login); }
        }

        if(!$user) wp_send_json_error(['message'=>'Invalid login details']);
        $res=wp_signon(['user_login'=>$user->user_login,'user_password'=>$password,'remember'=>true],is_ssl());
        if(is_wp_error($res)) wp_send_json_error(['message'=>'Invalid login details']);
        wp_set_current_user($res->ID);
        wp_send_json_success(['redirect'=>$this->frontend_url()]);
    }
    public function ajax_department_login(){
        global $wpdb; $code=strtoupper(sanitize_text_field($_POST['department_code']??'')); $password=(string)($_POST['password']??''); if(!$code||!$password) wp_send_json_error(['message'=>'Department ID and password are required']);
        $dept=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['departments']} WHERE department_code=%s AND login_enabled=1 AND status='active'",$code)); if(!$dept||!$dept->wp_user_id) wp_send_json_error(['message'=>'Invalid Department ID or login is disabled']);
        $u=get_user_by('id',$dept->wp_user_id); if(!$u) wp_send_json_error(['message'=>'Department login is not configured']); $res=wp_signon(['user_login'=>$u->user_login,'user_password'=>$password,'remember'=>true],is_ssl()); if(is_wp_error($res)) wp_send_json_error(['message'=>'Invalid Department ID or password']); wp_send_json_success(['redirect'=>$this->frontend_url()]);
    }
    public function ajax_front_logout(){ check_ajax_referer('bb_nonce','nonce'); wp_logout(); wp_send_json_success(['redirect'=>$this->frontend_url()]); }

    public function ajax_get_task_detail(){
        $this->verify(); global $wpdb;
        $id=absint($_POST['id']??0); $occ=sanitize_text_field($_POST['occurrence_date']??'');
        if(!$id || !$this->task_permission($id,'view')) wp_send_json_error(['message'=>'Task not found or access denied'],403);
        $task=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d",$id));
        if(!$task) wp_send_json_error(['message'=>'Task not found'],404);
        $occ_key=($task->is_template || $task->task_type==='Recurring') ? ($occ ?: (string)($task->due_date??'')) : 'one';
        $remarks=$wpdb->get_results($wpdb->prepare("SELECT r.*,e.name employee_name FROM {$this->tables['task_remarks']} r LEFT JOIN {$this->tables['employees']} e ON e.id=r.employee_id WHERE r.task_id=%d AND r.occurrence_key=%s ORDER BY r.id DESC LIMIT 100",$id,$occ_key));
        $assignments=$wpdb->get_results($wpdb->prepare("SELECT a.*,e.name employee_name FROM {$this->tables['entity_assignments']} a LEFT JOIN {$this->tables['employees']} e ON e.id=a.employee_id WHERE a.entity_type='task' AND a.entity_id=%d ORDER BY a.relation_type,a.id",$id));
        $reminders=$wpdb->get_results($wpdb->prepare("SELECT id,task_id,reminder_value,reminder_unit,sort_order FROM {$this->tables['task_reminders']} WHERE task_id=%d ORDER BY sort_order,id",$id));
        $after=$wpdb->get_row($wpdb->prepare("SELECT task_id,enabled,interval_value,interval_unit,active_start,active_end,all_day,duration_days,max_reminders FROM {$this->tables['task_overdue_reminders']} WHERE task_id=%d",$id));
        if(!$after) $after=(object)['task_id'=>$id,'enabled'=>0,'interval_value'=>1,'interval_unit'=>'Hours','active_start'=>'08:00:00','active_end'=>'20:00:00','all_day'=>0,'duration_days'=>0,'max_reminders'=>0];
        $attachments=$wpdb->get_results($wpdb->prepare("SELECT a.*,p.post_title file_name,p.guid file_url,p.post_mime_type,u.display_name uploaded_by_name FROM {$this->tables['attachments']} a JOIN {$wpdb->posts} p ON p.ID=a.attachment_id LEFT JOIN {$wpdb->users} u ON u.ID=a.uploaded_by WHERE a.task_id=%d ORDER BY a.id DESC",$id));
        foreach($attachments as $r){$url=wp_get_attachment_url($r->attachment_id);if($url)$r->file_url=$url;$r->thumb_url=wp_attachment_is_image($r->attachment_id)?wp_get_attachment_image_url($r->attachment_id,'thumbnail'):'';}
        $actions=['task_created','task_updated','task_in_progress','task_pending','task_reopened','task_stage_changed','task_completed','remark_added','subtask_added','subtask_updated','subtask_completed','subtask_reopened','subtask_deleted','attachment_uploaded','task_focus_added','task_focus_removed','task_pinned','task_unpinned','recurrence_paused','recurrence_resumed'];
        $ph=implode(',',array_fill(0,count($actions),'%s'));
        $sql="SELECT l.*,COALESCE(NULLIF(e.name,''),NULLIF(u.display_name,''),'System') user_name,e.name employee_name FROM {$this->tables['activity']} l LEFT JOIN {$this->tables['employees']} e ON e.id=l.employee_id LEFT JOIN {$wpdb->users} u ON u.ID=l.user_id WHERE l.task_id=%d AND l.action IN ($ph)";
        $args=array_merge([$id],$actions);
        if($occ!==''){$sql.=" AND (l.occurrence_date=%s OR l.occurrence_date IS NULL OR l.occurrence_date='')";$args[]=$occ;}
        $sql.=" ORDER BY l.id DESC LIMIT 100";
        $activity=$this->activity_rows_ist($wpdb->get_results($wpdb->prepare($sql,...$args)));
        wp_send_json_success(['task'=>['id'=>(int)$task->id,'description'=>(string)$task->description],'remarks'=>$remarks,'assignments'=>$assignments,'reminders'=>$reminders,'afterDueReminder'=>$after,'attachments'=>$attachments,'activity'=>$activity]);
    }

    public function ajax_get_task_activity(){
        $this->verify(); global $wpdb;
        $id=absint($_POST['id']??0); $occurrence_date=sanitize_text_field($_POST['occurrence_date']??'');
        if(!$id || !$this->task_permission($id,'view')) wp_send_json_error(['message'=>'Task not found or access denied'],403);
        $task=$wpdb->get_row($wpdb->prepare("SELECT id,task_name,task_type,frequency,work_area_id FROM {$this->tables['tasks']} WHERE id=%d",$id));
        if(!$task) wp_send_json_error(['message'=>'Task not found'],404);
        $days_limit=0;
        if(!empty($task->work_area_id)) $days_limit=(int)$wpdb->get_var($wpdb->prepare("SELECT remarks_view_days_limit FROM {$this->tables['work_areas']} WHERE id=%d",(int)$task->work_area_id));
        $allowed_actions=['task_in_progress','task_pending','task_stage_changed','task_completed','task_reopened','subtask_completed','subtask_reopened'];
        $placeholders=implode(',',array_fill(0,count($allowed_actions),'%s'));
        $sql="SELECT l.id,l.action,l.details,l.created_at,l.occurrence_date,COALESCE(NULLIF(e.name,''),NULLIF(u.display_name,''),'System') user_name
              FROM {$this->tables['activity']} l
              LEFT JOIN {$this->tables['employees']} e ON e.id=l.employee_id
              LEFT JOIN {$wpdb->users} u ON u.ID=l.user_id
              WHERE l.task_id=%d AND l.action IN ($placeholders)";
        $args=array_merge([$id],$allowed_actions);
        if($occurrence_date!==''){
            $sql.=" AND (l.occurrence_date=%s OR l.occurrence_date IS NULL OR l.occurrence_date='')";
            $args[]=$occurrence_date;
        }
        if($days_limit>0){
            // Rolling window: 1 = last 24 hours, 3 = last 72 hours.
            // This avoids hiding yesterday's activity merely because the calendar date changed.
            $now=current_datetime();
            $from=$now->sub(new DateInterval('P'.max(1,$days_limit).'D'));
            $from_date=$from->format('Y-m-d H:i:s');
            $sql.=" AND l.created_at >= %s";
            $args[]=$from_date;
        }
        $sql.=" ORDER BY l.id DESC LIMIT 500";
        $rows=$this->activity_rows_ist($wpdb->get_results($wpdb->prepare($sql,...$args)));
        wp_send_json_success(['task'=>$task,'activity'=>$rows]);
    }

    private function normalize_stage_name($name){
        $name=trim(wp_strip_all_tags((string)$name));
        $name=preg_replace('/\s+/u',' ',$name);
        return function_exists('mb_strtolower')?mb_strtolower($name,'UTF-8'):strtolower($name);
    }

    private function ensure_stage_master_stage($name,$color='#64748b'){
        global $wpdb;
        $name=trim(sanitize_text_field((string)$name));
        if(!$name) return '';
        $normalized=$this->normalize_stage_name($name);
        if($normalized==='in progress'){ $name='In Progress'; $color='#16a34a'; $normalized='in progress'; }
        else $color=sanitize_hex_color($color)?:'#64748b';
        $existing=$wpdb->get_row($wpdb->prepare("SELECT id,name,default_color,status FROM {$this->tables['stage_master']} WHERE normalized_name=%s LIMIT 1",$normalized));
        if($existing){
            if((string)$existing->status!=='active') $wpdb->update($this->tables['stage_master'],['status'=>'active','updated_at'=>current_time('mysql')],['id'=>(int)$existing->id]);
            return (string)$existing->name;
        }
        $wpdb->insert($this->tables['stage_master'],[
            'name'=>$name,'normalized_name'=>$normalized,'default_color'=>$color,'status'=>'active',
            'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')
        ]);
        return $name;
    }

    private function sync_stage_master_from_work_areas(){
        global $wpdb;
        if(empty($this->tables['stage_master'])) return;
        $this->ensure_stage_master_stage('In Progress','#16a34a');
        $areas=$wpdb->get_results("SELECT stages_json FROM {$this->tables['work_areas']}");
        foreach($areas as $area){
            foreach($this->work_area_stages($area) as $stage){
                $this->ensure_stage_master_stage((string)($stage['name']??''),(string)($stage['color']??'#64748b'));
            }
        }
    }

    private function register_work_area_stages_in_master($stages){
        $out=[];
        foreach((array)$stages as $stage){
            if(!is_array($stage)) continue;
            $color=sanitize_hex_color($stage['color']??'')?:'#64748b';
            $canonical=$this->ensure_stage_master_stage((string)($stage['name']??''),$color);
            if(!$canonical) continue;
            if(strcasecmp($canonical,'In Progress')===0) $out[]=['name'=>'In Progress','color'=>'#16a34a','is_default'=>1];
            else $out[]=['name'=>$canonical,'color'=>$color,'is_default'=>0];
        }
        return $this->sanitize_work_area_stages($out);
    }

    private function stage_master_state(){
        global $wpdb;
        // Keep imported/template-created Work Area stages discoverable without requiring a separate maintenance screen.
        $this->sync_stage_master_from_work_areas();
        $masters=$wpdb->get_results("SELECT id,name,normalized_name,default_color,status FROM {$this->tables['stage_master']} WHERE status='active' ORDER BY CASE WHEN normalized_name='in progress' THEN 0 ELSE 1 END,name ASC");
        $usage=[];
        $rows=$wpdb->get_results("SELECT a.id,a.name,a.stages_json,w.name workspace_name FROM {$this->tables['work_areas']} a LEFT JOIN {$this->tables['workspace_areas']} wa ON wa.work_area_id=a.id LEFT JOIN {$this->tables['workspaces']} w ON w.id=wa.workspace_id ORDER BY w.name,a.name");
        foreach($rows as $row){
            foreach($this->work_area_stages($row) as $stage){
                $key=$this->normalize_stage_name($stage['name']??'');
                if(!$key) continue;
                $label=trim(((string)($row->workspace_name??''))!==''?((string)$row->workspace_name.' › '.(string)$row->name):(string)$row->name);
                if($label!=='' && !in_array($label,$usage[$key]??[],true)) $usage[$key][]=$label;
            }
        }
        foreach($masters as $m){
            $list=$usage[(string)$m->normalized_name]??[];
            $m->usage_count=count($list);
            $m->usage=array_slice($list,0,5);
        }
        return $masters;
    }

    private function default_work_area_stages(){
        return [['name'=>'In Progress','color'=>'#16a34a','is_default'=>1]];
    }

    private function sanitize_work_area_stages($raw){
        if(is_string($raw)){
            $decoded=json_decode(wp_unslash($raw),true);
            $raw=is_array($decoded)?$decoded:[];
        }
        if(!is_array($raw)) $raw=[];
        $out=[]; $seen=[]; $has_default=false;
        foreach($raw as $row){
            if(!is_array($row)) continue;
            $name=trim(sanitize_text_field($row['name']??''));
            if(!$name) continue;
            $key=strtolower($name);
            if(isset($seen[$key])) continue;
            $seen[$key]=true;
            if($key==='in progress'){
                $out[]=['name'=>'In Progress','color'=>'#16a34a','is_default'=>1];
                $has_default=true;
            }else{
                $color=sanitize_hex_color($row['color']??'')?:'#64748b';
                $out[]=['name'=>$name,'color'=>$color,'is_default'=>0];
            }
            if(count($out)>=12) break;
        }
        if(!$has_default) array_unshift($out,['name'=>'In Progress','color'=>'#16a34a','is_default'=>1]);
        return array_slice($out,0,12);
    }

    private function work_area_stages($area){
        $raw='';
        if(is_object($area)) $raw=(string)($area->stages_json??'');
        elseif(is_array($area)) $raw=(string)($area['stages_json']??'');
        return $this->sanitize_work_area_stages($raw);
    }

    private function work_area_has_stage($work_area_id,$stage_name){
        global $wpdb;
        $stage_name=trim((string)$stage_name);
        if(!$work_area_id || !$stage_name) return false;
        $area=$wpdb->get_row($wpdb->prepare("SELECT stages_json FROM {$this->tables['work_areas']} WHERE id=%d",absint($work_area_id)));
        if(!$area) return false;
        foreach($this->work_area_stages($area) as $stage){
            if(strcasecmp((string)$stage['name'],$stage_name)===0) return (string)$stage['name'];
        }
        return false;
    }

    public function ajax_get_state(){
        $this->verify(); global $wpdb;
        // v4.16.76: progressive state loading. The first Workboard request asks for
        // scope=core so heavy, module-specific datasets are not built or transferred
        // until the user actually opens one of those modules.
        $state_scope=sanitize_key($_POST['scope']??'full');
        $core_only=($state_scope==='core');
        // v4.16.81: heavy modules can now request only their own dataset instead of the
        // old monolithic full-state payload. Full remains the backwards-compatible default.
        $module_scope=sanitize_key($_POST['module_scope']??'');
        $valid_module_scopes=['notes','folders','math','templates','attendance','leave','activity','scoreboard','reports'];
        if(!in_array($module_scope,$valid_module_scopes,true)) $module_scope='';
        $module_scoped=(!$core_only && $module_scope!=='');
        $load_notes=(!$core_only && (!$module_scoped || in_array($module_scope,['notes','folders'],true)));
        $load_math=(!$core_only && (!$module_scoped || $module_scope==='math'));
        $load_templates_module=(!$core_only && (!$module_scoped || $module_scope==='templates'));
        $load_attendance_module=(!$core_only && (!$module_scoped || in_array($module_scope,['attendance','leave'],true)));
        $load_activity_module=(!$core_only && (!$module_scoped || $module_scope==='activity'));
        $load_score_module=(!$core_only && (!$module_scoped || in_array($module_scope,['scoreboard','reports'],true)));

        // Task scope is independent of module scope. Module requests normally use
        // task_scope=none so Notes/Math/Attendance/etc never drag the whole task database.
        $task_scope=sanitize_key($_POST['task_scope']??($core_only?'none':'all'));
        if(!in_array($task_scope,['none','workspace','focus','pending','billing','slug','all'],true)) $task_scope='none';
        $task_workspace_id=absint($_POST['workspace_id']??0);
        $task_workspace_slug=sanitize_title($_POST['workspace_slug']??'');
        $task_slug=sanitize_title($_POST['task_slug']??'');
        if($task_scope==='workspace' && !$task_workspace_id && $task_workspace_slug){
            $task_workspace_id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['workspaces']} WHERE slug=%s LIMIT 1",$task_workspace_slug));
        }
        // Fast startup path: expensive maintenance must not run on every UI refresh.
        // Recurring refresh is throttled per site; slug migration runs once per plugin version.
        $refresh_key='bb_recurring_refresh_'.get_current_blog_id();
        if(!get_transient($refresh_key)){
            set_transient($refresh_key,1,60);
            $this->refresh_recurring_instances();
        }
        if((string)get_option('bb_task_slug_migration_version','')!==self::VERSION){
            $this->migrate_task_slugs();
            update_option('bb_task_slug_migration_version',self::VERSION,false);
        }
        $allowed=$this->allowed_workspace_ids();
        $allowed_notespaces=$this->allowed_notespace_ids();
        $ws_where=$allowed===null?'':($allowed?' WHERE w.id IN ('.implode(',',array_map('intval',$allowed)).')':' WHERE 1=0');
        $active_filter=$ws_where ? $ws_where." AND w.status NOT IN ('trash','archived')" : " WHERE w.status NOT IN ('trash','archived')";
        $archive_filter=$ws_where ? $ws_where." AND w.status='archived'" : " WHERE w.status='archived'";
        $trash_filter=$ws_where ? $ws_where." AND w.status='trash'" : " WHERE w.status='trash'";
        $workspaces=$wpdb->get_results("SELECT w.*,wt.name source_template_name FROM {$this->tables['workspaces']} w LEFT JOIN {$this->tables['workspace_templates']} wt ON wt.id=w.source_template_id{$active_filter} ORDER BY w.sort_order ASC,w.id ASC");
        $archived_workspaces=$wpdb->get_results("SELECT w.*,wt.name source_template_name FROM {$this->tables['workspaces']} w LEFT JOIN {$this->tables['workspace_templates']} wt ON wt.id=w.source_template_id{$archive_filter} ORDER BY w.sort_order ASC,w.id ASC");
        $trashed_workspaces=$wpdb->get_results("SELECT w.*,wt.name source_template_name FROM {$this->tables['workspaces']} w LEFT JOIN {$this->tables['workspace_templates']} wt ON wt.id=w.source_template_id{$trash_filter} ORDER BY w.sort_order ASC,w.id ASC");
        $folder_links=$wpdb->get_results("SELECT folder_id,workspace_id FROM {$this->tables['folder_workspaces']} ORDER BY folder_id,sort_order,workspace_id");
        $folder_map=[];
        foreach($folder_links as $fl) $folder_map[(int)$fl->workspace_id][]=(int)$fl->folder_id;
        foreach([$workspaces,$archived_workspaces,$trashed_workspaces] as $workspace_list){
            foreach($workspace_list as $w){
                $w->folder_ids=array_values($folder_map[(int)$w->id]??[]);
                if(!$w->folder_id && $w->folder_ids) $w->folder_id=$w->folder_ids[0];
            }
        }

        // Notes and Math are lazy-loaded after startup.
        $notespaces=[]; $note_areas=[]; $note_cards=[]; $mathspaces=[]; $math_areas=[]; $math_entries=[];
        if($load_notes){
            // Notespace access + optional direct Note Area sharing.
            $allowed_note_area_ids=$this->allowed_note_area_ids();

            if($allowed_notespaces===null){
                $notespaces=$wpdb->get_results("SELECT * FROM {$this->tables['notespaces']} WHERE status='active' ORDER BY sort_order ASC,id ASC");
                $visible_notespace_ids=array_values(array_unique(array_map(fn($n)=>(int)$n->id,$notespaces)));
                $note_areas=$visible_notespace_ids?$wpdb->get_results(
                    "SELECT * FROM {$this->tables['note_areas']} WHERE notespace_id IN (".implode(',',$visible_notespace_ids).") ORDER BY notespace_id,sort_order,id"
                ):[];
                $visible_note_area_ids=array_values(array_unique(array_map(fn($a)=>(int)$a->id,$note_areas)));
                $note_cards=$visible_note_area_ids?$wpdb->get_results(
                    "SELECT * FROM {$this->tables['note_cards']} WHERE note_area_id IN (".implode(',',$visible_note_area_ids).") ORDER BY note_area_id,sort_order,id"
                ):[];
            }else{
                // A direct Note Area share also makes its parent Notespace visible,
                // but sibling Note Areas remain hidden unless inherited/directly shared.
                $direct_area_notespace_ids=[];
                if($allowed_note_area_ids){
                    $direct_area_notespace_ids=array_map('intval',$wpdb->get_col(
                        "SELECT DISTINCT notespace_id FROM {$this->tables['note_areas']} WHERE id IN (".implode(',',array_map('intval',$allowed_note_area_ids)).")"
                    ));
                }
                $visible_notespace_ids=array_values(array_unique(array_filter(array_merge(array_map('intval',$allowed_notespaces),$direct_area_notespace_ids))));
                $notespaces=$visible_notespace_ids?$wpdb->get_results(
                    "SELECT * FROM {$this->tables['notespaces']} WHERE status='active' AND id IN (".implode(',',$visible_notespace_ids).") ORDER BY sort_order ASC,id ASC"
                ):[];
                $note_areas=$allowed_note_area_ids?$wpdb->get_results(
                    "SELECT * FROM {$this->tables['note_areas']} WHERE id IN (".implode(',',array_map('intval',$allowed_note_area_ids)).") ORDER BY notespace_id,sort_order,id"
                ):[];
                $visible_note_area_ids=array_values(array_unique(array_map(fn($a)=>(int)$a->id,$note_areas)));
                $note_cards=$visible_note_area_ids?$wpdb->get_results(
                    "SELECT * FROM {$this->tables['note_cards']} WHERE note_area_id IN (".implode(',',$visible_note_area_ids).") ORDER BY note_area_id,sort_order,id"
                ):[];
            }

            $folder_note_links=$wpdb->get_results("SELECT folder_id,notespace_id FROM {$this->tables['folder_notespaces']} ORDER BY folder_id,sort_order,notespace_id");
            $folder_note_map=[];
            foreach($folder_note_links as $fl) $folder_note_map[(int)$fl->notespace_id][]=(int)$fl->folder_id;
            $pinned_notespace_ids=array_values(array_unique(array_filter(array_map('absint',(array)get_option('bb_pinned_notespace_ids',[])))));
            $pinned_notespace_map=array_fill_keys($pinned_notespace_ids,true);
            foreach($notespaces as $n){
                $n->folder_ids=array_values($folder_note_map[(int)$n->id]??[]);
                $n->is_pinned=isset($pinned_notespace_map[(int)$n->id])?1:0;
            }

            if($load_math){
            $allowed_math_area_ids=$this->allowed_math_area_ids();
            if($allowed_math_area_ids===null){
                $mathspaces=$wpdb->get_results("SELECT * FROM {$this->tables['mathspaces']} WHERE status='active' ORDER BY sort_order,id");
                $math_areas=$wpdb->get_results("SELECT * FROM {$this->tables['math_areas']} ORDER BY mathspace_id,sort_order,id");
                $math_entries=$wpdb->get_results("SELECT * FROM {$this->tables['math_entries']} ORDER BY math_area_id,sort_order,id");
            }else{
                $math_areas=$allowed_math_area_ids?$wpdb->get_results("SELECT * FROM {$this->tables['math_areas']} WHERE id IN (".implode(',',array_map('intval',$allowed_math_area_ids)).") ORDER BY mathspace_id,sort_order,id"):[];
                $visible_mathspace_ids=array_values(array_unique(array_map(fn($a)=>(int)$a->mathspace_id,$math_areas)));
                $mathspaces=$visible_mathspace_ids?$wpdb->get_results("SELECT * FROM {$this->tables['mathspaces']} WHERE status='active' AND id IN (".implode(',',$visible_mathspace_ids).") ORDER BY sort_order,id"):[];
                $math_entries=$allowed_math_area_ids?$wpdb->get_results("SELECT * FROM {$this->tables['math_entries']} WHERE math_area_id IN (".implode(',',array_map('intval',$allowed_math_area_ids)).") ORDER BY math_area_id,sort_order,id"):[];
            }
            }

        }

        // Math can be loaded independently from Notes.
        if($load_math && !$load_notes){
            $allowed_math_area_ids=$this->allowed_math_area_ids();
            if($allowed_math_area_ids===null){
                $mathspaces=$wpdb->get_results("SELECT * FROM {$this->tables['mathspaces']} WHERE status='active' ORDER BY sort_order,id");
                $math_areas=$wpdb->get_results("SELECT * FROM {$this->tables['math_areas']} ORDER BY mathspace_id,sort_order,id");
                $math_entries=$wpdb->get_results("SELECT * FROM {$this->tables['math_entries']} ORDER BY math_area_id,sort_order,id");
            }else{
                $math_areas=$allowed_math_area_ids?$wpdb->get_results("SELECT * FROM {$this->tables['math_areas']} WHERE id IN (".implode(',',array_map('intval',$allowed_math_area_ids)).") ORDER BY mathspace_id,sort_order,id"):[];
                $visible_mathspace_ids=array_values(array_unique(array_map(fn($a)=>(int)$a->mathspace_id,$math_areas)));
                $mathspaces=$visible_mathspace_ids?$wpdb->get_results("SELECT * FROM {$this->tables['mathspaces']} WHERE status='active' AND id IN (".implode(',',$visible_mathspace_ids).") ORDER BY sort_order,id"):[];
                $math_entries=$allowed_math_area_ids?$wpdb->get_results("SELECT * FROM {$this->tables['math_entries']} WHERE math_area_id IN (".implode(',',array_map('intval',$allowed_math_area_ids)).") ORDER BY math_area_id,sort_order,id"):[];
            }
        }

        $folders=$wpdb->get_results("SELECT f.*,
            (SELECT COUNT(*) FROM {$this->tables['folder_workspaces']} fw JOIN {$this->tables['workspaces']} w ON w.id=fw.workspace_id WHERE fw.folder_id=f.id AND w.status NOT IN ('trash','archived')) workspace_count,
            (SELECT COUNT(*) FROM {$this->tables['folder_notespaces']} fn JOIN {$this->tables['notespaces']} n ON n.id=fn.notespace_id WHERE fn.folder_id=f.id AND n.status='active') notespace_count
            FROM {$this->tables['folders']} f ORDER BY f.sort_order ASC,f.id ASC");
        if($allowed!==null){
            $visible_folder_ids=[];
            foreach($workspaces as $w) foreach(($w->folder_ids??[]) as $fid) $visible_folder_ids[]=(int)$fid;
            // Keep a Folder visible when it contains any Workspace or Notespace the current User can access.
            foreach($notespaces as $n) foreach(($n->folder_ids??[]) as $fid) $visible_folder_ids[]=(int)$fid;
            $visible_folder_ids=array_values(array_unique(array_filter($visible_folder_ids)));
            $folders=array_values(array_filter($folders,fn($f)=>in_array((int)$f->id,$visible_folder_ids,true)));
        }
        $workspace_templates=[]; $work_area_templates=[];
        if($load_templates_module){
            $workspace_templates=$wpdb->get_results("SELECT wt.*, (SELECT COUNT(*) FROM {$this->tables['workspaces']} w WHERE w.source_template_id=wt.id) connected_count FROM {$this->tables['workspace_templates']} wt ORDER BY wt.updated_at DESC,wt.name");
            $work_area_templates=$wpdb->get_results("SELECT id,name,snapshot,created_at,updated_at FROM {$this->tables['work_area_templates']} ORDER BY updated_at DESC,name");
            foreach($work_area_templates as $wat){ $snap=json_decode($wat->snapshot,true); $wat->source_area_name=is_array($snap)?sanitize_text_field($snap['area']['name']??''):''; $wat->task_count=is_array($snap)?count($snap['tasks']??[]):0; $wat->snapshot_data=is_array($snap)?$snap:[]; unset($wat->snapshot); }
            foreach($workspace_templates as $wt){ $snap=json_decode($wt->snapshot,true); $wt->area_count=is_array($snap)?count($snap['areas']??[]):0; $wt->task_count=is_array($snap)?count($snap['tasks']??[]):0; $wt->snapshot_data=is_array($snap)?$snap:[]; unset($wt->snapshot); }
        }
        $areas=$wpdb->get_results("SELECT a.*,e.name head_name FROM {$this->tables['work_areas']} a LEFT JOIN {$this->tables['employees']} e ON e.id=a.head_employee_id ORDER BY a.name");
        foreach($areas as $area) $area->stages=$this->work_area_stages($area);
        $maps=$wpdb->get_results("SELECT wa.workspace_id,wa.work_area_id,wa.sort_order FROM {$this->tables['workspace_areas']} wa JOIN {$this->tables['workspaces']} w ON w.id=wa.workspace_id WHERE w.status NOT IN ('trash','archived') ORDER BY wa.workspace_id,wa.sort_order,wa.work_area_id");
        if($allowed!==null){
            if($party=$this->current_party()){
                $party_area_ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT work_area_id FROM {$this->tables['work_area_parties']} WHERE party_id=%d",(int)$party->id)));
                $maps=array_values(array_filter($maps,fn($m)=>in_array((int)$m->workspace_id,$allowed,true)&&in_array((int)$m->work_area_id,$party_area_ids,true)));
                $areas=array_values(array_filter($areas,fn($a)=>in_array((int)$a->id,$party_area_ids,true)));
            }else{
            $emp=$this->current_employee(); $eid=$emp?(int)$emp->id:0;
            $direct_ws=$eid?array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT entity_id FROM {$this->tables['entity_assignments']} WHERE employee_id=%d AND entity_type='workspace'",$eid))):[];
            $direct_areas=$eid?array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT entity_id FROM {$this->tables['entity_assignments']} WHERE employee_id=%d AND entity_type='work_area'",$eid))):[];
            $task_areas=$eid?array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT DISTINCT t.work_area_id FROM {$this->tables['entity_assignments']} ea JOIN {$this->tables['tasks']} t ON t.id=ea.entity_id WHERE ea.employee_id=%d AND ea.entity_type='task'",$eid))):[];
            $subtask_areas=$eid?array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT DISTINCT t.work_area_id FROM {$this->tables['entity_assignments']} ea JOIN {$this->tables['checklists']} c ON c.id=ea.entity_id JOIN {$this->tables['tasks']} t ON t.id=c.task_id WHERE ea.employee_id=%d AND ea.entity_type='subtask'",$eid))):[];
            $descendant_areas=array_values(array_unique(array_merge($direct_areas,$task_areas,$subtask_areas)));
            $maps=array_values(array_filter($maps,fn($m)=>in_array((int)$m->workspace_id,$allowed,true) && (in_array((int)$m->workspace_id,$direct_ws,true)||in_array((int)$m->work_area_id,$descendant_areas,true))));
            $visible_area_ids=array_values(array_unique(array_map(fn($m)=>(int)$m->work_area_id,$maps)));
            $areas=array_values(array_filter($areas,fn($a)=>in_array((int)$a->id,$visible_area_ids,true)));
            }
        }
        $emps=$wpdb->get_results("SELECT e.*,a.name work_area_name FROM {$this->tables['employees']} e LEFT JOIN {$this->tables['work_areas']} a ON a.id=e.work_area_id ORDER BY e.name");
        // Attendance/Leave history is module-specific and can be substantial.
        $attendance_combos=[]; $attendance_events=[]; $attendance_audit=[]; $attendance_holidays=[]; $attendance_leaves=[]; $attendance_weekly_off_claims=[]; $attendance_payroll_adjustments=[];
        $att_today=$this->attendance_ist_now('Y-m-d');
        $att_month_start=substr($att_today,0,8).'01';
        if($load_attendance_module){
            $attendance_combos=$wpdb->get_results("SELECT * FROM {$this->tables['attendance_combos']} ORDER BY sort_order,id");
            if(!$this->is_privileged_user()){ foreach($emps as $emp_row){ if(isset($emp_row->salary)) unset($emp_row->salary); } }
            // Employees normally receive their own attendance state.
            // A Supervisor must also receive the attendance state of employees assigned
            // to that Supervisor; otherwise the UI can show "IN" while the server already
            // has an IN event and the punch is rejected as a stale state.
            if($this->is_privileged_user()){
                $attendance_events=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tables['attendance_events']} WHERE attendance_date BETWEEN %s AND %s ORDER BY attendance_date DESC,punched_at ASC,id ASC",$att_month_start,$att_today));
            }else{
                $att_me=$this->current_employee();
                $attendance_events=[];
                if($att_me){
                    $attendance_employee_ids=[(int)$att_me->id];

                    // Current Supervisor relationship: Supervisor may view and punch these employees.
                    $supervised_ids=array_map('intval',$wpdb->get_col($wpdb->prepare(
                        "SELECT id FROM {$this->tables['employees']}
                         WHERE status<>'inactive'
                           AND attendance_enabled=1
                           AND attendance_supervisor_employee_id=%d",
                        (int)$att_me->id
                    )));

                    // View Attendance is inverse: each employee stores the Users allowed to view that employee.
                    $viewer_target_ids=[];
                    $viewer_rows=$wpdb->get_results("SELECT id,attendance_viewer_employee_ids FROM {$this->tables['employees']} WHERE status<>'inactive' AND attendance_enabled=1");
                    foreach($viewer_rows as $viewer_row){
                        $viewer_ids=json_decode((string)($viewer_row->attendance_viewer_employee_ids??''),true);
                        if(!is_array($viewer_ids)) $viewer_ids=[];
                        if(in_array((int)$att_me->id,array_map('intval',$viewer_ids),true)) $viewer_target_ids[]=(int)$viewer_row->id;
                    }

                    $attendance_employee_ids=array_values(array_unique(array_filter(array_merge($attendance_employee_ids,$supervised_ids,$viewer_target_ids))));
                    $placeholders=implode(',',array_fill(0,count($attendance_employee_ids),'%d'));
                    $from_date=$att_month_start;
                    $sql="SELECT * FROM {$this->tables['attendance_events']}
                          WHERE employee_id IN ($placeholders)
                            AND attendance_date BETWEEN %s AND %s
                          ORDER BY attendance_date DESC,punched_at ASC,id ASC";
                    $params=array_merge($attendance_employee_ids,[$from_date,$att_today]);
                    $attendance_events=$wpdb->get_results($wpdb->prepare($sql,...$params));
                }
            }
            $this->attendance_add_ist_display($attendance_events);
            $attendance_audit=$this->is_privileged_user()?$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tables['attendance_audit']} WHERE created_at >= %s ORDER BY id DESC LIMIT 300",$att_month_start.' 00:00:00')):[];
            $this->attendance_add_ist_display($attendance_audit);
            $attendance_holidays=$wpdb->get_results("SELECT * FROM {$this->tables['attendance_holidays']} ORDER BY holiday_date ASC,id ASC");
            $attendance_leaves=[];
            $leave_me=$this->current_employee();
            if($this->is_privileged_user()){
                $attendance_leaves=$wpdb->get_results("SELECT l.*,e.name employee_name,a.name approver_name FROM {$this->tables['attendance_leaves']} l LEFT JOIN {$this->tables['employees']} e ON e.id=l.employee_id LEFT JOIN {$this->tables['employees']} a ON a.id=l.approver_employee_id ORDER BY l.from_date DESC,l.id DESC");
            }elseif($leave_me){
                $leave_visible_ids=[(int)$leave_me->id];
                $leave_visible_ids=array_merge($leave_visible_ids,array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->tables['employees']} WHERE status<>'inactive' AND attendance_supervisor_employee_id=%d",(int)$leave_me->id))));
                $leave_viewer_rows=$wpdb->get_results("SELECT id,attendance_viewer_employee_ids FROM {$this->tables['employees']} WHERE status<>'inactive' AND attendance_viewer_employee_ids IS NOT NULL AND attendance_viewer_employee_ids<>''");
                foreach($leave_viewer_rows as $vr){$v=json_decode((string)$vr->attendance_viewer_employee_ids,true);if(is_array($v)&&in_array((int)$leave_me->id,array_map('intval',$v),true))$leave_visible_ids[]=(int)$vr->id;}
                $leave_visible_ids=array_values(array_unique(array_filter($leave_visible_ids)));
                $leave_in=implode(',',array_fill(0,count($leave_visible_ids),'%d'));
                $leave_sql="SELECT l.*,e.name employee_name,a.name approver_name FROM {$this->tables['attendance_leaves']} l LEFT JOIN {$this->tables['employees']} e ON e.id=l.employee_id LEFT JOIN {$this->tables['employees']} a ON a.id=l.approver_employee_id WHERE l.employee_id IN ($leave_in) ORDER BY l.from_date DESC,l.id DESC";
                $attendance_leaves=$wpdb->get_results($wpdb->prepare($leave_sql,...$leave_visible_ids));
            }
            if($this->is_privileged_user()){
                $attendance_weekly_off_claims=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tables['attendance_weekly_off_claims']} WHERE claim_date BETWEEN %s AND %s ORDER BY claim_date DESC,id DESC",$att_month_start,$att_today));
                $attendance_payroll_adjustments=$wpdb->get_results($wpdb->prepare("SELECT a.*,e.name created_by_name FROM {$this->tables['attendance_payroll_adjustments']} a LEFT JOIN {$this->tables['employees']} e ON e.id=a.created_by_employee_id WHERE a.attendance_date BETWEEN %s AND %s ORDER BY a.attendance_date DESC,a.id DESC",$att_month_start,$att_today));
            }else{
                $att_me=$this->current_employee();
                $attendance_weekly_off_claims=[]; $attendance_payroll_adjustments=[];
                if($att_me){
                    $visible_attendance_ids=[(int)$att_me->id];
                    $visible_attendance_ids=array_merge($visible_attendance_ids,array_map('intval',$wpdb->get_col($wpdb->prepare(
                        "SELECT id FROM {$this->tables['employees']}
                         WHERE status<>'inactive'
                           AND attendance_enabled=1
                           AND attendance_supervisor_employee_id=%d",
                        (int)$att_me->id
                    ))));
                    $viewer_rows=$wpdb->get_results("SELECT id,attendance_viewer_employee_ids FROM {$this->tables['employees']} WHERE status<>'inactive' AND attendance_enabled=1");
                    foreach($viewer_rows as $viewer_row){
                        $viewer_ids=json_decode((string)($viewer_row->attendance_viewer_employee_ids??''),true);
                        if(is_array($viewer_ids) && in_array((int)$att_me->id,array_map('intval',$viewer_ids),true)) $visible_attendance_ids[]=(int)$viewer_row->id;
                    }
                    $visible_attendance_ids=array_values(array_unique(array_filter($visible_attendance_ids)));
                    $in=implode(',',array_fill(0,count($visible_attendance_ids),'%d'));

                    $claims_sql="SELECT * FROM {$this->tables['attendance_weekly_off_claims']}
                                 WHERE employee_id IN ($in)
                                   AND claim_date BETWEEN %s AND %s
                                 ORDER BY claim_date DESC,id DESC";
                    $attendance_weekly_off_claims=$wpdb->get_results($wpdb->prepare($claims_sql,...array_merge($visible_attendance_ids,[$att_month_start,$att_today])));

                    $adj_sql="SELECT a.*,e.name created_by_name
                              FROM {$this->tables['attendance_payroll_adjustments']} a
                              LEFT JOIN {$this->tables['employees']} e ON e.id=a.created_by_employee_id
                              WHERE a.employee_id IN ($in)
                                AND a.attendance_date BETWEEN %s AND %s
                              ORDER BY a.attendance_date DESC,a.id DESC";
                    $attendance_payroll_adjustments=$wpdb->get_results($wpdb->prepare($adj_sql,...array_merge($visible_attendance_ids,[$att_month_start,$att_today])));
                }
            }

        }
        $parties=$this->is_privileged_user()
            ? $wpdb->get_results("SELECT * FROM {$this->tables['parties']} WHERE status='active' ORDER BY party_name,id")
            : $wpdb->get_results("SELECT id,party_name,party_type,city,state,status FROM {$this->tables['parties']} WHERE status='active' ORDER BY party_name,id");
        $this->sync_people_masters();
        $departments=$this->is_privileged_user()?$wpdb->get_results("SELECT id,department_code,name,status,created_at,updated_at FROM {$this->tables['departments']} ORDER BY name,id"):[];
        $designations=$this->is_privileged_user()?$wpdb->get_results("SELECT * FROM {$this->tables['designations']} ORDER BY name,id"):[];
        $employee_departments=[]; $workspace_departments=[];

        // Task state is the largest part of Workboard. In core mode, query only the
        // task slice required by the current view instead of every task in every Workspace.
        $one_time=[]; $templates=[]; $archived_tasks=[]; $tasks=[]; $ids=[]; $checks=[];
        $task_extra=''; $template_extra=''; $load_archived=false;
        if($task_scope==='workspace' && $task_workspace_id){
            $task_extra=' AND t.workspace_id='.(int)$task_workspace_id;
            $template_extra=$task_extra; $load_archived=true;
        }elseif($task_scope==='focus'){
            $task_extra=' AND t.is_focus=1'; $template_extra=' AND t.is_focus=1';
        }elseif($task_scope==='pending'){
            $task_extra=" AND t.status IN ('Pending','In Progress','Overdue')";
            // Recurring templates must remain available so their current actionable occurrence can be generated.
            $template_extra=" AND t.recurrence_status='active'";
        }elseif($task_scope==='billing'){
            $task_extra=" AND t.is_billable=1 AND t.status='Completed'";
            $template_extra=' AND 1=0';
        }elseif($task_scope==='slug' && $task_slug){
            $safe_slug=esc_sql($task_slug);
            $task_extra=" AND t.slug='{$safe_slug}'"; $template_extra=$task_extra;
        }elseif($task_scope==='none'){
            $task_extra=' AND 1=0'; $template_extra=' AND 1=0';
        }else{
            $task_scope='all';
            // The Tasks master does not display archived rows; loading them here used to make
            // this view almost as expensive as the old full-state request.
            $load_archived=false;
        }

        if($task_scope!=='none'){
            // Operational/list views receive compact task rows. Full form/detail fields are
            // fetched only when a Task is opened.
            $pending_select="t.id,t.workspace_id,t.work_area_id,t.task_name,t.slug,t.status,t.stage_name,t.priority,t.labels,t.due_date,t.due_time,t.task_type,t.frequency,t.assignee_id,t.is_focus,t.is_pinned,t.sort_order,t.created_at,t.updated_at,t.completed_at,t.is_billable,t.billable_amount,t.billing_updated_at";
            $all_select="t.id,t.workspace_id,t.work_area_id,t.task_name,t.slug,t.status,t.stage_name,t.priority,t.labels,t.due_date,t.due_time,t.task_type,t.frequency,t.interval_value,t.interval_unit,t.start_date,t.assignee_id,t.recurrence_status,t.completion_result,t.is_focus,t.is_pinned,t.sort_order,t.created_at,t.updated_at,t.completed_at";
            $one_time_select=($task_scope==='pending')?$pending_select:(($core_only && $task_scope==='all')?$all_select:'t.*');
            $one_time=$wpdb->get_results("SELECT {$one_time_select},w.name workspace_name,a.name work_area_name,a.color work_area_color,e.name assignee_name
                FROM {$this->tables['tasks']} t
                LEFT JOIN {$this->tables['workspaces']} w ON w.id=t.workspace_id
                LEFT JOIN {$this->tables['work_areas']} a ON a.id=t.work_area_id
                LEFT JOIN {$this->tables['employees']} e ON e.id=t.assignee_id
                WHERE t.is_template=0 AND t.template_id IS NULL AND t.archived_at IS NULL AND (w.status IS NULL OR w.status NOT IN ('trash','archived')) AND (a.status IS NULL OR a.status NOT IN ('trash','archived')){$task_extra}
                ORDER BY t.due_date,t.due_time,t.id DESC");
            $template_select=($core_only && $task_scope==='all')
                ? "t.id,t.workspace_id,t.work_area_id,t.task_name,t.slug,t.status,t.stage_name,t.priority,t.labels,t.due_date,t.due_time,t.task_type,t.frequency,t.interval_value,t.interval_unit,t.start_date,t.assignee_id,t.recurrence_status,t.is_focus,t.is_pinned,t.sort_order,t.created_at,t.updated_at"
                : "t.*";
            $templates=$wpdb->get_results("SELECT {$template_select},w.name workspace_name,a.name work_area_name,a.color work_area_color,e.name assignee_name
                FROM {$this->tables['tasks']} t
                LEFT JOIN {$this->tables['workspaces']} w ON w.id=t.workspace_id
                LEFT JOIN {$this->tables['work_areas']} a ON a.id=t.work_area_id
                LEFT JOIN {$this->tables['employees']} e ON e.id=t.assignee_id
                WHERE t.is_template=1 AND t.archived_at IS NULL AND (w.status IS NULL OR w.status NOT IN ('trash','archived')) AND (a.status IS NULL OR a.status NOT IN ('trash','archived')){$template_extra} ORDER BY t.task_name");

            if($load_archived){
                $archived_tasks=$wpdb->get_results("SELECT t.*,w.name workspace_name,a.name work_area_name,a.color work_area_color,e.name assignee_name
                    FROM {$this->tables['tasks']} t
                    LEFT JOIN {$this->tables['workspaces']} w ON w.id=t.workspace_id
                    LEFT JOIN {$this->tables['work_areas']} a ON a.id=t.work_area_id
                    LEFT JOIN {$this->tables['employees']} e ON e.id=t.assignee_id
                    WHERE t.is_template=0 AND t.template_id IS NULL AND t.archived_at IS NOT NULL AND (w.status IS NULL OR w.status NOT IN ('trash','archived')) AND (a.status IS NULL OR a.status NOT IN ('trash','archived')){$task_extra} ORDER BY t.archived_at DESC,t.id DESC");
                $archived_occ_where=$task_workspace_id?(' AND t.workspace_id='.(int)$task_workspace_id):'';
                $archived_occ=$wpdb->get_results("SELECT h.*,t.task_name,t.workspace_id,t.work_area_id,t.priority,t.labels,t.frequency,t.task_type,t.assignee_id,t.id template_task_id,w.name workspace_name,a.name work_area_name,a.color work_area_color,e.name assignee_name
                    FROM {$this->tables['history']} h
                    JOIN {$this->tables['tasks']} t ON t.id=h.task_id
                    LEFT JOIN {$this->tables['workspaces']} w ON w.id=t.workspace_id
                    LEFT JOIN {$this->tables['work_areas']} a ON a.id=t.work_area_id
                    LEFT JOIN {$this->tables['employees']} e ON e.id=t.assignee_id
                    WHERE h.status='Archived' AND t.archived_at IS NULL AND (w.status IS NULL OR w.status NOT IN ('trash','archived')) AND (a.status IS NULL OR a.status NOT IN ('trash','archived')){$archived_occ_where} ORDER BY h.due_date DESC,h.id DESC");
                $template_by_id=[];
                foreach($templates as $tpl_row) $template_by_id[(int)$tpl_row->id]=$tpl_row;
                // Archived recurring rows may refer to templates not present in a filtered template slice.
                if($archived_occ){
                    $missing_template_ids=[];
                    foreach($archived_occ as $h) if(!isset($template_by_id[(int)$h->task_id])) $missing_template_ids[]=(int)$h->task_id;
                    $missing_template_ids=array_values(array_unique(array_filter($missing_template_ids)));
                    if($missing_template_ids){
                        foreach($wpdb->get_results("SELECT t.*,w.name workspace_name,a.name work_area_name,a.color work_area_color,e.name assignee_name FROM {$this->tables['tasks']} t LEFT JOIN {$this->tables['workspaces']} w ON w.id=t.workspace_id LEFT JOIN {$this->tables['work_areas']} a ON a.id=t.work_area_id LEFT JOIN {$this->tables['employees']} e ON e.id=t.assignee_id WHERE t.id IN (".implode(',',$missing_template_ids).")") as $mt) $template_by_id[(int)$mt->id]=$mt;
                    }
                }
                foreach($archived_occ as $h){
                    $tpl=$template_by_id[(int)$h->task_id]??null; if(!$tpl) continue;
                    $x=$this->history_as_task($h,$tpl,$h->workspace_name,$h->work_area_name,$h->work_area_color,$h->assignee_name);
                    $x->status='Archived'; $x->is_archived=1; $archived_tasks[]=$x;
                }
            }

            if($allowed!==null){
                $one_time=array_values(array_filter($one_time,fn($t)=>in_array((int)$t->workspace_id,$allowed,true) && $this->task_permission((int)$t->id,'view')));
                $templates=array_values(array_filter($templates,fn($t)=>in_array((int)$t->workspace_id,$allowed,true) && $this->task_permission((int)$t->id,'view')));
                $archived_tasks=array_values(array_filter($archived_tasks,fn($t)=>in_array((int)$t->workspace_id,$allowed,true) && $this->task_permission((int)($t->template_id ?: $t->id),'view')));
            }
            $tasks=$one_time;
            $today=current_time('Y-m-d');
            $history_by_task=[]; $history_by_task_date=[]; $exception_by_task=[];
            $template_ids=array_values(array_filter(array_map(fn($x)=>(int)$x->id,$templates)));
            if($template_ids){
                $template_csv=implode(',',array_map('intval',$template_ids));
                // Pending uses compact history rows but keeps every status in the date map so
                // completed occurrences can be skipped without one SQL lookup per recurrence date.
                $hist_select=($task_scope==='pending')
                    ? 'id,task_id,occurrence_date,due_date,due_time,status,stage_name,result,completed_at'
                    : '*';
                $hist_where=($task_scope==='pending') ? " AND (status='Overdue' OR occurrence_date>='".esc_sql($today)."')" : " AND (status IN ('Overdue','Completed') OR occurrence_date>='".esc_sql($today)."')";
                $hist_rows=$wpdb->get_results("SELECT {$hist_select} FROM {$this->tables['history']} WHERE task_id IN ({$template_csv}){$hist_where} ORDER BY task_id,due_date,due_time,id");
                foreach($hist_rows as $hr){
                    $tid=(int)$hr->task_id;
                    $history_by_task[$tid][]=$hr;
                    $history_by_task_date[$tid.'|'.(string)$hr->occurrence_date]=$hr;
                }
                if($task_scope==='pending'){
                    $ex_rows=$wpdb->get_results("SELECT task_id,occurrence_date FROM {$this->tables['recurrence_exceptions']} WHERE task_id IN ({$template_csv})");
                    foreach($ex_rows as $ex) $exception_by_task[(int)$ex->task_id][(string)$ex->occurrence_date]=true;
                }
            }
            foreach($templates as $tpl){
                $tid=(int)$tpl->id;
                $is_daily=(strcasecmp((string)$tpl->frequency,'Daily')===0); $hist=[];
                $status_map=[];
                foreach(($history_by_task[$tid]??[]) as $hr){
                    $status_map[(string)$hr->occurrence_date]=(string)$hr->status;
                    if($task_scope==='pending'){
                        if((string)$hr->status==='Overdue') $hist[]=$hr;
                    }else{
                        if($hr->status==='Overdue') $hist[]=$hr;
                        elseif($hr->status==='Completed' && (!$is_daily || substr((string)$hr->completed_at,0,10)===$today)) $hist[]=$hr;
                    }
                }
                $seen=[]; $exception_dates=$exception_by_task[$tid]??[];
                foreach($hist as $h){
                    if($h->status==='Overdue'){
                        $visible=($task_scope==='pending')
                            ? $this->recurring_overdue_visible_preloaded($tpl,$h->occurrence_date,$today,$exception_dates)
                            : $this->recurring_overdue_visible($tpl,$h->occurrence_date,$today);
                        if(!$visible) continue;
                    }
                    $seen[$h->occurrence_date]=true;
                    $tasks[]=($task_scope==='pending')
                        ? $this->history_as_task_preloaded($h,$tpl,$tpl->workspace_name,$tpl->work_area_name,$tpl->work_area_color,$tpl->assignee_name,$exception_dates,$today)
                        : $this->history_as_task($h,$tpl,$tpl->workspace_name,$tpl->work_area_name,$tpl->work_area_color,$tpl->assignee_name);
                }
                $next_date=($task_scope==='pending')
                    ? ($seen ? array_key_first($seen) : $this->next_actionable_occurrence_preloaded($tpl,$today,$status_map,$exception_dates))
                    : $this->next_actionable_occurrence($tpl,$today);
                if($is_daily && $next_date!==$today) $next_date=null;
                if($next_date && !isset($seen[$next_date])){
                    $existing=$history_by_task_date[$tid.'|'.$next_date]??null;
                    if($existing && in_array((string)$existing->status,['Completed','Archived'],true)) continue;
                    $tasks[]=$existing
                        ? (($task_scope==='pending')
                            ? $this->history_as_task_preloaded($existing,$tpl,$tpl->workspace_name,$tpl->work_area_name,$tpl->work_area_color,$tpl->assignee_name,$exception_dates,$today)
                            : $this->history_as_task($existing,$tpl,$tpl->workspace_name,$tpl->work_area_name,$tpl->work_area_color,$tpl->assignee_name))
                        : $this->virtual_task($tpl,$next_date,$tpl->workspace_name,$tpl->work_area_name,$tpl->work_area_color,$tpl->assignee_name);
                }
            }

            $all=array_merge($one_time,$templates); $ids=array_values(array_unique(array_filter(array_map(fn($x)=>(int)$x->id,$all))));
            $needs_checklists=in_array($task_scope,['workspace','focus','pending','slug'],true);
            if($ids && $needs_checklists){
                $check_select=($task_scope==='pending')?'id,task_id,item_text,sort_order':'*';
                $rows=$wpdb->get_results("SELECT {$check_select} FROM {$this->tables['checklists']} WHERE task_id IN (".implode(',',$ids).") ORDER BY sort_order,id");
                foreach($rows as $r) $checks[$r->task_id][]=$r;
            }
            foreach($tasks as $t){$base=(int)($t->template_id ?: $t->id);$t->checklist=$checks[$base]??[];}
            foreach($templates as $t) $t->checklist=$checks[$t->id]??[];

            if($ids && $needs_checklists){
                $progress_rows=$wpdb->get_results("SELECT task_id,subtask_id,occurrence_key,is_done,completed_by_employee_id,completed_at FROM {$this->tables['subtask_progress']} WHERE task_id IN (".implode(',',$ids).")");
                $progress_map=[]; foreach($progress_rows as $pr) $progress_map[$pr->task_id.'|'.$pr->occurrence_key.'|'.$pr->subtask_id]=$pr;
                foreach($tasks as $t){
                    $base=(int)($t->template_id ?: $t->id);$occ=$t->template_id?(string)($t->occurrence_date ?: $t->due_date):'one';$done_count=0;
                    foreach($t->checklist as $c){$key=$base.'|'.$occ.'|'.$c->id;$pr=$progress_map[$key]??null;$c->is_done=$pr?(int)$pr->is_done:0;$c->completed_at=$pr->completed_at??null;$c->completed_by_employee_id=$pr->completed_by_employee_id??null;if($c->is_done)$done_count++;}
                    $t->subtask_total=count($t->checklist);$t->subtask_done=$done_count;
                }
            }else{
                foreach($tasks as $t){$t->subtask_total=0;$t->subtask_done=0;}
            }
            // v4.18.168: Goals — target, unit and progress for this occurrence (recurring) or in total (one-time).
            $goal_ids=[];
            if($ids){ foreach($wpdb->get_results("SELECT id,goal_target,goal_unit FROM {$this->tables['tasks']} WHERE id IN (".implode(',',$ids).") AND goal_target>0") as $g) $goal_ids[(int)$g->id]=$g; }
            $goal_sum=[];
            if($goal_ids){ foreach($wpdb->get_results("SELECT task_id,occurrence_key,SUM(amount) total,COUNT(*) entries,SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(goal_target,0) ORDER BY id DESC),',',1) day_target,SUBSTRING_INDEX(GROUP_CONCAT(IF(goal_target>0,goal_unit,NULL) ORDER BY id DESC SEPARATOR '\x1f'),'\x1f',1) day_unit FROM {$this->tables['goal_progress']} WHERE task_id IN (".implode(',',array_keys($goal_ids)).") GROUP BY task_id,occurrence_key") as $g) $goal_sum[(int)$g->task_id.'|'.$g->occurrence_key]=$g; }
            foreach(array_merge($tasks,$templates) as $t){
                $base=(int)preg_replace('/^r_(\d+).*$/','$1',(string)($t->template_id ?: $t->id));
                $g=$goal_ids[$base]??null;
                if(!$g){ $t->goal_target=0; $t->goal_unit=''; $t->goal_done=0; $t->goal_entries=0; continue; }
                $occ=($t->template_id || !empty($t->is_template))?(string)($t->occurrence_date ?: $t->due_date ?: ''):'one';
                $sum=$goal_sum[$base.'|'.$occ]??null;
                $t->goal_target=($sum && (int)$sum->day_target>0)?(int)$sum->day_target:(int)$g->goal_target; $t->goal_unit=($sum && (int)$sum->day_target>0)?(string)$sum->day_unit:(string)$g->goal_unit;
                $t->goal_done=$sum?(int)$sum->total:0; $t->goal_entries=$sum?(int)$sum->entries:0;
            }
        }else{
            $today=current_time('Y-m-d');
        }

        $history_count=0; $history_oldest=null; $score_archive=[]; $access=[];
        if(!$core_only && (!$module_scoped || $load_score_module)){
            $history_count=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->tables['history']}");
            $history_oldest=$wpdb->get_var("SELECT MIN(due_date) FROM {$this->tables['history']}");
        }
        if($load_score_module){
            $score_archive=$wpdb->get_results("SELECT assignee_id,workspace_id,work_area_id,SUM(due_count) due_count,SUM(early_count) early_count,SUM(on_time_count) on_time_count,SUM(late_count) late_count,SUM(missed_count) missed_count,SUM(skipped_count) skipped_count,SUM(total_points) total_points FROM {$this->tables['score_archive']} GROUP BY assignee_id,workspace_id,work_area_id");
            if($allowed!==null) $score_archive=array_values(array_filter($score_archive,fn($x)=>in_array((int)$x->workspace_id,$allowed,true)));
        }
        if(!$core_only && !$module_scoped){
            $access=$wpdb->get_results("SELECT x.*,e.name employee_name,w.name workspace_name FROM {$this->tables['workspace_access']} x LEFT JOIN {$this->tables['employees']} e ON e.id=x.employee_id LEFT JOIN {$this->tables['workspaces']} w ON w.id=x.workspace_id ORDER BY e.name,w.name");
            if($allowed!==null) $access=array_values(array_filter($access,fn($x)=>in_array((int)$x->workspace_id,$allowed,true)));
        }
        if(($core_only && in_array($task_scope,['pending','billing'],true)) || ($module_scoped && !in_array($module_scope,['activity','scoreboard','reports'],true))){
            $activity=[];
        }else{
            $activity=$core_only
                ? $wpdb->get_results($wpdb->prepare("SELECT l.id,l.task_id,l.workspace_id,l.work_area_id,l.action,l.details,l.created_at,l.occurrence_date,COALESCE(NULLIF(e.name,''),NULLIF(u.display_name,''),'System') user_name,e.name employee_name,w.name workspace_name,a.name work_area_name,t.task_name FROM {$this->tables['activity']} l LEFT JOIN {$wpdb->users} u ON u.ID=l.user_id LEFT JOIN {$this->tables['employees']} e ON e.id=l.employee_id LEFT JOIN {$this->tables['workspaces']} w ON w.id=l.workspace_id LEFT JOIN {$this->tables['work_areas']} a ON a.id=l.work_area_id LEFT JOIN {$this->tables['tasks']} t ON t.id=l.task_id WHERE l.created_at >= %s ORDER BY l.id DESC LIMIT 120",$today.' 00:00:00'))
                // Avoid joining Employees again by WP user/email here: duplicate Employee mappings can multiply one activity row.
                : $wpdb->get_results("SELECT l.*,u.display_name user_name,u.user_email wp_user_email,e.name employee_name,(SELECT e2.name FROM {$this->tables['employees']} e2 WHERE e2.wp_user_id=l.user_id AND e2.name<>'' ORDER BY e2.id ASC LIMIT 1) wp_employee_name,(SELECT e3.name FROM {$this->tables['employees']} e3 WHERE u.user_email IS NOT NULL AND LOWER(e3.email)=LOWER(u.user_email) AND e3.name<>'' ORDER BY e3.id ASC LIMIT 1) email_employee_name,w.name workspace_name,a.name work_area_name,t.task_name FROM {$this->tables['activity']} l LEFT JOIN {$wpdb->users} u ON u.ID=l.user_id LEFT JOIN {$this->tables['employees']} e ON e.id=l.employee_id LEFT JOIN {$this->tables['workspaces']} w ON w.id=l.workspace_id LEFT JOIN {$this->tables['work_areas']} a ON a.id=l.work_area_id LEFT JOIN {$this->tables['tasks']} t ON t.id=l.task_id ORDER BY l.id DESC LIMIT 300");
        }
        if($allowed!==null) $activity=array_values(array_filter($activity,fn($x)=>!$x->workspace_id || in_array((int)$x->workspace_id,$allowed,true)));
        $activity=$this->activity_rows_ist($activity);
        $wp_users=(!$core_only && $this->is_privileged_user())?array_map(fn($u)=>['id'=>$u->ID,'name'=>$u->display_name,'email'=>$u->user_email],get_users(['fields'=>['ID','display_name','user_email']])):[];
        // Admin-only diagnostics/logs are loaded lazily from Settings, not during normal startup.
        $template_versions=[];
        if($core_only){
            $assignment_where="a.entity_type IN ('workspace','work_area')";
            if($ids){
                $id_csv=implode(',',array_map('intval',$ids));
                $assignment_where.=" OR (a.entity_type='task' AND a.entity_id IN ($id_csv)) OR (a.entity_type='subtask' AND a.entity_id IN (SELECT id FROM {$this->tables['checklists']} WHERE task_id IN ($id_csv)))";
            }
            $assignments=$wpdb->get_results("SELECT a.*,e.name employee_name FROM {$this->tables['entity_assignments']} a LEFT JOIN {$this->tables['employees']} e ON e.id=a.employee_id WHERE ($assignment_where) ORDER BY a.entity_type,a.entity_id,a.relation_type,a.id");
        }else{
            $assignments=$wpdb->get_results("SELECT a.*,e.name employee_name FROM {$this->tables['entity_assignments']} a LEFT JOIN {$this->tables['employees']} e ON e.id=a.employee_id ORDER BY a.entity_type,a.entity_id,a.relation_type,a.id");
        }
        $assignment_map=[]; foreach($assignments as $as) $assignment_map[(string)$as->entity_type.'|'.(int)$as->entity_id][]=$as;
        $remarks=[]; $remark_map=[]; $remark_count_map=[]; $remark_latest_map=[];
        if($core_only){
            if(!in_array($task_scope,['pending','billing','none'],true)){
                $remark_counts=$ids?$wpdb->get_results("SELECT task_id,occurrence_key,COUNT(*) remark_count,MAX(id) latest_id FROM {$this->tables['task_remarks']} WHERE task_id IN (".implode(',',array_map('intval',$ids)).") GROUP BY task_id,occurrence_key"):[];
                $latest_ids=array_values(array_filter(array_map(fn($r)=>(int)$r->latest_id,$remark_counts)));
                $latest_rows=[];
                if($latest_ids){
                    foreach($wpdb->get_results("SELECT r.id,r.task_id,r.occurrence_key,r.remark,r.created_at,e.name employee_name FROM {$this->tables['task_remarks']} r LEFT JOIN {$this->tables['employees']} e ON e.id=r.employee_id WHERE r.id IN (".implode(',',$latest_ids).")") as $lr) $latest_rows[(int)$lr->id]=$lr;
                }
                foreach($remark_counts as $rc){$k=(int)$rc->task_id.'|'.(string)$rc->occurrence_key;$remark_count_map[$k]=(int)$rc->remark_count;$remark_latest_map[$k]=$latest_rows[(int)$rc->latest_id]??null;}
            }
        }elseif($task_scope!=='none'){
            $remarks=$wpdb->get_results("SELECT r.*,e.name employee_name FROM {$this->tables['task_remarks']} r LEFT JOIN {$this->tables['employees']} e ON e.id=r.employee_id ORDER BY r.id DESC LIMIT 1000");
            foreach($remarks as $rm) $remark_map[(int)$rm->task_id.'|'.(string)$rm->occurrence_key][]=$rm;
        }
        $task_id_filter=$ids?(' WHERE task_id IN ('.implode(',',array_map('intval',$ids)).')'):' WHERE 1=0';
        $reminder_map=[]; $overdue_reminder_map=[];
        $load_task_reminder_meta=($task_scope!=='none') && !($core_only && in_array($task_scope,['pending','billing'],true));
        if($load_task_reminder_meta){
            $reminder_rows=$wpdb->get_results("SELECT id,task_id,reminder_value,reminder_unit,sort_order FROM {$this->tables['task_reminders']}{$task_id_filter} ORDER BY task_id,sort_order,id");
            foreach($reminder_rows as $rr) $reminder_map[(int)$rr->task_id][]=$rr;
            $overdue_reminder_rows=$wpdb->get_results("SELECT task_id,enabled,interval_value,interval_unit,active_start,active_end,all_day,duration_days,max_reminders FROM {$this->tables['task_overdue_reminders']}{$task_id_filter}");
            foreach($overdue_reminder_rows as $orow) $overdue_reminder_map[(int)$orow->task_id]=$orow;
        }
        foreach($tasks as $t){
            $base=(int)($t->template_id ?: $t->id); $occ=$t->template_id?(string)($t->occurrence_date ?: $t->due_date):'one';
            $t->assignments=$assignment_map['task|'.$base]??[];
            $remark_key=$base.'|'.$occ;
            if($core_only){
                $t->remarks=[]; $t->remark_count=(int)($remark_count_map[$remark_key]??0); $lr=$remark_latest_map[$remark_key]??null; $t->latest_remark=$lr?(string)$lr->remark:'';
            }else{
                $rr=$remark_map[$remark_key]??[]; $t->remarks=$rr; $t->remark_count=count($rr); $t->latest_remark=$rr?$rr[0]->remark:'';
            }
            $t->reminders=[];
            if($load_task_reminder_meta){
                if(!empty($t->reminder_enabled)){ $t->reminders[]=(object)['id'=>0,'reminder_value'=>(int)$t->reminder_value,'reminder_unit'=>(string)$t->reminder_unit,'sort_order'=>0,'is_primary'=>1]; foreach(($reminder_map[$base]??[]) as $xr) $t->reminders[]=$xr; }
                $t->after_due_reminder=$overdue_reminder_map[$base]??(object)['task_id'=>$base,'enabled'=>0,'interval_value'=>1,'interval_unit'=>'Hours','active_start'=>'08:00:00','active_end'=>'20:00:00','all_day'=>0,'duration_days'=>0,'max_reminders'=>0];
            }else $t->after_due_reminder=null;
            foreach($t->checklist as $c) $c->assignments=$assignment_map['subtask|'.(int)$c->id]??[];
        }
        foreach($templates as $t){ $t->assignments=$assignment_map['task|'.(int)$t->id]??[]; $t->after_due_reminder=$load_task_reminder_meta?($overdue_reminder_map[(int)$t->id]??(object)['task_id'=>(int)$t->id,'enabled'=>0,'interval_value'=>1,'interval_unit'=>'Hours','active_start'=>'08:00:00','active_end'=>'20:00:00','all_day'=>0,'duration_days'=>0,'max_reminders'=>0]):null; }
        $workspace_party_rows=$wpdb->get_results("SELECT wp.workspace_id,wp.party_id,COALESCE(NULLIF(wp.rights,''),'view') rights,p.party_name FROM {$this->tables['workspace_parties']} wp JOIN {$this->tables['parties']} p ON p.id=wp.party_id ORDER BY p.party_name");
        $workspace_party_map=[]; foreach($workspace_party_rows as $r) $workspace_party_map[(int)$r->workspace_id][]=$r;
        foreach($workspaces as $w){
            $w->assignments=$assignment_map['workspace|'.(int)$w->id]??[];
            $w->party_assignments=array_values(array_map(fn($r)=>['party_id'=>(int)$r->party_id,'party_name'=>$r->party_name,'rights'=>$r->rights],$workspace_party_map[(int)$w->id]??[]));
        }
        foreach($notespaces as $n) $n->assignments=$assignment_map['notespace|'.(int)$n->id]??[];
        foreach($note_areas as $a) $a->assignments=$assignment_map['note_area|'.(int)$a->id]??[];
        foreach($mathspaces as $m) $m->assignments=$assignment_map['mathspace|'.(int)$m->id]??[];
        foreach($math_areas as $a) $a->assignments=$assignment_map['math_area|'.(int)$a->id]??[];
        $work_area_party_rows=$wpdb->get_results("SELECT wap.work_area_id,wap.party_id,COALESCE(NULLIF(wap.rights,''),'view') rights,p.party_name FROM {$this->tables['work_area_parties']} wap JOIN {$this->tables['parties']} p ON p.id=wap.party_id ORDER BY p.party_name");
        $work_area_party_map=[]; foreach($work_area_party_rows as $r) $work_area_party_map[(int)$r->work_area_id][]=$r;
        foreach($areas as $a){
            $a->assignments=$assignment_map['work_area|'.(int)$a->id]??[];
            $rows=$work_area_party_map[(int)$a->id]??[];
            $a->party_assignments=array_values(array_map(fn($r)=>['party_id'=>(int)$r->party_id,'party_name'=>$r->party_name,'rights'=>$r->rights],$rows));
            $a->party_ids=array_values(array_map(fn($r)=>(int)$r->party_id,$rows));
        }
        $diagnostics=[];
        $messaging_state=[];
        $message_log_recent=[];
        $wallet_ledger_recent=[];
        $stage_master=$this->stage_master_state();
        $labels_master=$this->labels_master_state();
        if($core_only){
            // Keep startup payload focused on board rendering. Long task text and full remark data
            // are fetched only when a Task is opened/edited.
            foreach(array_merge($tasks,$templates,$archived_tasks) as $ct){
                if(isset($ct->description)) $ct->description='';
                if(isset($ct->completion_notes)) $ct->completion_notes='';
                if(isset($ct->remarks)) $ct->remarks=[];
            }
            if($task_scope==='pending'){
                // The Pending Tasks table needs only a compact operational representation.
                // Remove recurrence/template internals and other form-only fields after virtual
                // occurrences have already been calculated server-side.
                $pending_keep=['id','template_id','occurrence_date','task_name','slug','workspace_id','workspace_name','work_area_id','work_area_name','work_area_color','status','stage_name','priority','labels','due_date','due_time','task_type','frequency','assignee_id','assignee_name','subtask_total','subtask_done','checklist','assignments'];
                $pending_keep=array_flip($pending_keep);
                foreach($tasks as $ct){
                    foreach(array_keys(get_object_vars($ct)) as $k) if(!isset($pending_keep[$k])) unset($ct->$k);
                    foreach(($ct->checklist??[]) as $c){
                        $ck=['id'=>1,'task_id'=>1,'item_text'=>1,'sort_order'=>1,'is_done'=>1,'completed_at'=>1,'completed_by_employee_id'=>1,'assignments'=>1];
                        foreach(array_keys(get_object_vars($c)) as $k) if(!isset($ck[$k])) unset($c->$k);
                    }
                }
                // Templates are an internal implementation detail for this slice; the UI uses
                // generated occurrences in tasks[] and does not need the template records again.
                $templates=[]; $archived_tasks=[];
            }
        }
        wp_send_json_success([
            'stateScope'=>$core_only?'core':'full','moduleScope'=>$module_scope,'taskScope'=>$task_scope,'taskWorkspaceId'=>$task_workspace_id,
            'workspaces'=>$workspaces,'archivedWorkspaces'=>$archived_workspaces,'trashedWorkspaces'=>$trashed_workspaces,'folders'=>$folders,
            'notespaces'=>$notespaces,'noteAreas'=>$note_areas,'noteCards'=>$note_cards,'mathspaces'=>$mathspaces,'mathAreas'=>$math_areas,'mathEntries'=>$math_entries,
            'workspaceTemplates'=>$workspace_templates,'workAreaTemplates'=>$work_area_templates,'workAreas'=>$areas,'workspaceAreas'=>$maps,'stageMaster'=>$stage_master,'labelsMaster'=>$labels_master,'employees'=>$emps,'parties'=>$parties,'attendanceCombos'=>$attendance_combos,'attendanceEvents'=>$attendance_events,'attendanceAudit'=>$attendance_audit,'attendanceHolidays'=>$attendance_holidays,'attendanceLeaves'=>$attendance_leaves,'attendanceWeeklyOffClaims'=>$attendance_weekly_off_claims,'attendancePayrollAdjustments'=>$attendance_payroll_adjustments,'departments'=>$departments,'designations'=>$designations,'employeeDepartments'=>$employee_departments,'workspaceDepartments'=>$workspace_departments,'tasks'=>$tasks,'templates'=>$templates,'archivedTasks'=>$archived_tasks,
            'today'=>$today,'attendanceToday'=>$att_today,'attendanceNow'=>$this->attendance_ist_now('Y-m-d H:i:s'),'attendanceTimezone'=>'Asia/Kolkata','now'=>current_time('mysql'),'scoreArchive'=>$score_archive,
            'retention'=>['months'=>(int)get_option('bb_history_retention_months',12),'historyCount'=>$history_count,'oldest'=>$history_oldest,'purgeProof'=>(int)get_option('bb_purge_proof_with_history',0)],
            'access'=>$access,'activity'=>$activity,'wpUsers'=>$wp_users,'identity'=>$this->identity(),'currentEmployeeId'=>(($ce=$this->current_employee())?(int)$ce->id:0),
            'notifications'=>$this->current_notifications(),'attachments'=>($core_only||$task_scope==='none')?[]:$this->task_attachments_state($allowed),
            'notificationSettings'=>['assignment'=>(int)get_option('bb_notify_assignment',1),'dueSoon'=>(int)get_option('bb_notify_due_soon',1),'overdue'=>(int)get_option('bb_notify_overdue',1),'dailySummary'=>0,'morningPending'=>(int)get_option('bb_notify_morning_pending',1),'email'=>(int)get_option('bb_notify_email',0),'whatsapp'=>(int)get_option('bb_meta_whatsapp_enabled',0),'metaPhoneNumberId'=>(string)get_option('bb_meta_whatsapp_phone_number_id',''),'metaTokenConfigured'=>$this->site_own_token_configured(get_current_blog_id())?1:0,'metaApiVersion'=>(string)get_option('bb_meta_whatsapp_api_version','v25.0'),'metaCountryCode'=>(string)get_option('bb_meta_whatsapp_country_code','91'),'metaTplAssignment'=>(string)get_option('bb_meta_tpl_assignment','workboard_task_assigned'),'metaTplAssignmentEnabled'=>(int)get_option('bb_meta_tpl_assignment_enabled',1),'metaTplUpdate'=>(string)get_option('bb_meta_tpl_update','workboard_task_updated'),'metaTplUpdateEnabled'=>(int)get_option('bb_meta_tpl_update_enabled',1),'metaTplDue'=>(string)get_option('bb_meta_tpl_due','workboard_task_due'),'metaTplDueEnabled'=>(int)get_option('bb_meta_tpl_due_enabled',1),'metaTplOverdue'=>(string)get_option('bb_meta_tpl_overdue','workboard_task_overdue'),'metaTplOverdueEnabled'=>(int)get_option('bb_meta_tpl_overdue_enabled',1),'metaTplDaily'=>(string)get_option('bb_meta_tpl_daily','workboard_pending_task_summary'),'metaTplDailyEnabled'=>0,'metaTplMorning'=>(string)get_option('bb_meta_tpl_morning','workboard_pending_morningreminder'),'metaTplMorningEnabled'=>(int)get_option('bb_meta_tpl_morning_enabled',1),'metaTplUrgent'=>(string)get_option('bb_meta_tpl_urgent','workboard_urgent_reminder'),'metaTplUrgentEnabled'=>(int)get_option('bb_meta_tpl_urgent_enabled',1),'metaTplDueReminder'=>(string)get_option('bb_meta_tpl_due_reminder','workboard_due_reminder'),'metaTplDueReminderEnabled'=>(int)get_option('bb_meta_tpl_due_reminder_enabled',1),'metaTplReminder'=>(string)get_option('bb_meta_tpl_reminder','workboard_task_reminder'),'metaTplReminderEnabled'=>(int)get_option('bb_meta_tpl_reminder_enabled',1),'metaLanguage'=>'en','metaTestMobile'=>(string)get_option('bb_meta_whatsapp_test_mobile','')],
            'aiSettings'=>['enabled'=>(int)get_option('bb_ai_enabled',0),'provider'=>(string)get_option('bb_ai_provider','openai'),'apiKeyConfigured'=>get_option('bb_ai_api_key','')?1:0,'model'=>(string)get_option('bb_ai_model','gpt-5'),'maxFileMb'=>(int)get_option('bb_ai_max_file_mb',10)],
            'brandingSettings'=>['logoUrl'=>$this->brand_logo_url(),'hasLogo'=>$this->brand_logo_url()?1:0],
            'storageSettings'=>$this->current_site_storage_state(false),
            'orgSettings'=>[
                'orgName'=>(string)get_option('bb_org_name',''),
                'logoUrl'=>$this->org_logo_url(),
                'mobile'=>(string)get_option('bb_org_mobile',''),
                'email'=>(string)get_option('bb_org_email',''),
                'address'=>(string)get_option('bb_org_address',''),
                'city'=>(string)get_option('bb_org_city',''),
                'state'=>(string)get_option('bb_org_state',''),
                'pin'=>(string)get_option('bb_org_pin',''),
                'gstin'=>(string)get_option('bb_org_gstin',''),
                'pan'=>(string)get_option('bb_org_pan',''),
                'website'=>(string)get_option('bb_org_website',''),
                'showWorkspaceDeleteIcons'=>(int)get_option('bb_show_workspace_delete_icons',1),
                'showAttendance'=>(int)get_option('bb_show_module_attendance',1),
                'showNotes'=>(int)get_option('bb_show_module_notes',1),
                'showMath'=>(int)get_option('bb_show_module_math',1),
                'showBilling'=>(int)get_option('bb_show_module_billing',0),
                'showLeave'=>(int)get_option('bb_show_module_leave',1),
                'showTimeSheet'=>(int)get_option('bb_show_module_timesheet',0),
                'timeSheetMinutesMode'=>1,
                'showCallSpace'=>(int)get_option('bb_show_module_callspace',0),
                'showDocSpace'=>(int)get_option('bb_show_module_docspace',0),
                'enablePartyShare'=>(int)get_option('bb_enable_party_share',0),
                'attendanceDefaultInTime'=>(string)get_option('bb_attendance_default_in_time','09:00'),
                'attendanceDefaultOutTime'=>(string)get_option('bb_attendance_default_out_time','18:00'),
                'attendanceDefaultWeeklyOff'=>(string)get_option('bb_attendance_default_weekly_off','Sunday')
            ],
            'attendanceSettings'=>['defaultInTime'=>(string)get_option('bb_attendance_default_in_time','09:00'),'defaultOutTime'=>(string)get_option('bb_attendance_default_out_time','18:00'),'graceMinutes'=>(int)get_option('bb_attendance_grace_minutes',15),'standardBreakMinutes'=>(int)get_option('bb_attendance_standard_break_minutes',45),'autoBreakAfterMinutes'=>(int)get_option('bb_attendance_auto_break_after_minutes',360),'missingOutCutoff'=>(string)get_option('bb_attendance_missing_out_cutoff','23:55'),'missingOutCutoffMode'=>(string)get_option('bb_attendance_missing_out_cutoff_mode','same_day'),'defaultWeeklyOff'=>(string)get_option('bb_attendance_default_weekly_off','Sunday'),'salaryBasis'=>(string)get_option('bb_attendance_salary_basis','calendar_days'),'locationMode'=>(string)get_option('bb_attendance_location_mode','optional'),'whatsappSummaryActive'=>(int)get_option('bb_attendance_whatsapp_active',0),'whatsappNumber'=>(string)get_option('bb_attendance_whatsapp_number',''),'whatsappMorning'=>(int)get_option('bb_attendance_whatsapp_morning',1),'whatsappMorningTime'=>(string)get_option('bb_attendance_whatsapp_morning_time','11:00'),'whatsappEvening'=>(int)get_option('bb_attendance_whatsapp_evening',1),'whatsappEveningTime'=>(string)get_option('bb_attendance_whatsapp_evening_time','20:00'),'whatsappTemplate'=>(string)get_option('bb_attendance_whatsapp_template','workboard_daily_attendance_summary'),'whatsappTestNumber'=>(string)get_option('bb_attendance_whatsapp_test_number',''),'whatsappLastTestStatus'=>(string)get_option('bb_attendance_whatsapp_last_test_status',''),'whatsappLastTestMessageId'=>(string)get_option('bb_attendance_whatsapp_last_test_message_id',''),'whatsappLastTestError'=>(string)get_option('bb_attendance_whatsapp_last_test_error','')],
            'messagingSettings'=>$messaging_state,'messageLogRecent'=>$message_log_recent,'walletLedgerRecent'=>$wallet_ledger_recent,
            'templateVersions'=>$template_versions,'assignments'=>$assignments,'remarks'=>$remarks,'diagnostics'=>$diagnostics
        ]);
    }

    public function ajax_get_admin_heavy_state(){
        $this->verify();
        if(!$this->is_privileged_user()) wp_send_json_error(['message'=>'Not allowed'],403);
        global $wpdb;
        $diagnostics=['dbVersion'=>get_option('bb_db_version'),'phpVersion'=>PHP_VERSION,'wpVersion'=>get_bloginfo('version'),'timezone'=>wp_timezone_string(),'cron'=>[
            'historyCleanup'=>wp_next_scheduled('bb_daily_history_cleanup')?:0,'hourlyNotifications'=>wp_next_scheduled('bb_hourly_notifications')?:0,'dailySummary'=>wp_next_scheduled('bb_morning_task_notifications')?:0,'taskReminder'=>wp_next_scheduled('bb_task_reminder_tick')?:0,'lastReminderTick'=>(int)get_option('bb_last_reminder_tick',0)],
            'counts'=>[]];
        foreach($this->tables as $k=>$tbl) $diagnostics['counts'][$k]=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$tbl}");
        wp_send_json_success([
            'diagnostics'=>$diagnostics,
            'storageSettings'=>$this->current_site_storage_state(true),
            'messagingSettings'=>$this->current_site_messaging_state(),
            'messageLogRecent'=>$this->site_message_log_rows(get_current_blog_id(),25,0),
            'walletLedgerRecent'=>$this->site_wallet_ledger_rows(get_current_blog_id(),10),
            'templateVersions'=>$this->template_versions_state()
        ]);
    }


    private function task_template_key($task){
        global $wpdb;
        if(!empty($task->template_key)) return $task->template_key;
        $key=str_replace('-','',wp_generate_uuid4());
        $wpdb->update($this->tables['tasks'],['template_key'=>$key,'updated_at'=>current_time('mysql')],['id'=>$task->id]);
        return $key;
    }

    private function workspace_snapshot($workspace_id){
        global $wpdb;
        $workspace=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['workspaces']} WHERE id=%d",$workspace_id));
        if(!$workspace) return null;
        $areas=$wpdb->get_results($wpdb->prepare("SELECT a.*,wa.sort_order FROM {$this->tables['workspace_areas']} wa JOIN {$this->tables['work_areas']} a ON a.id=wa.work_area_id WHERE wa.workspace_id=%d AND a.status NOT IN ('trash','archived') ORDER BY wa.sort_order,a.id",$workspace_id));
        $area_map=[]; $area_data=[];
        foreach($areas as $a){
            $area_map[$a->id]=$a->name;
            $area_data[]=['name'=>$a->name,'description'=>$a->description,'color'=>$a->color,'status'=>$a->status,'stages_json'=>$a->stages_json?:wp_json_encode($this->default_work_area_stages()),'sort_order'=>(int)$a->sort_order];
        }
        $tasks=$wpdb->get_results($wpdb->prepare("SELECT t.* FROM {$this->tables['tasks']} t LEFT JOIN {$this->tables['work_areas']} a ON a.id=t.work_area_id WHERE t.workspace_id=%d AND t.archived_at IS NULL AND (a.status IS NULL OR a.status NOT IN ('trash','archived')) ORDER BY t.work_area_id,t.id",$workspace_id));
        $task_data=[]; $today=current_time('Y-m-d');
        foreach($tasks as $t){
            $key=$this->task_template_key($t);
            $checks=$wpdb->get_col($wpdb->prepare("SELECT item_text FROM {$this->tables['checklists']} WHERE task_id=%d ORDER BY sort_order,id",$t->id));
            $due_offset=null; $start_offset=null;
            if($t->task_type==='One Time' && $t->due_date) $due_offset=(int)round((strtotime($t->due_date)-strtotime($today))/DAY_IN_SECONDS);
            if($t->task_type==='Recurring' && $t->start_date) $start_offset=(int)round((strtotime($t->start_date)-strtotime($today))/DAY_IN_SECONDS);
            $task_data[]=[
                'key'=>$key,'task_name'=>$t->task_name,'work_area_name'=>$area_map[$t->work_area_id]??'',
                'assignee_id'=>(int)$t->assignee_id,'priority'=>$t->priority,'labels'=>$t->labels,'task_type'=>$t->task_type,
                'frequency'=>$t->frequency,'interval_value'=>(int)$t->interval_value,'interval_unit'=>$t->interval_unit,'overdue_policy'=>$t->overdue_policy?:'until_next',
                'due_offset_days'=>$due_offset,'start_offset_days'=>$start_offset,'end_date'=>$t->end_date,
                'due_time'=>$t->due_time,'description'=>$t->description,'estimated_minutes'=>(int)$t->estimated_minutes,
                'checklist'=>array_values(array_filter(array_map('strval',$checks)))
            ];
        }
        return ['schema'=>1,'saved_on'=>$today,'workspace'=>['name'=>$workspace->name,'description'=>$workspace->description,'color'=>$workspace->color],'areas'=>$area_data,'tasks'=>$task_data];
    }

    private function get_template_snapshot($template_id){
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['workspace_templates']} WHERE id=%d",$template_id));
        if(!$row) return [null,null];
        $snap=json_decode($row->snapshot,true);
        return [$row,is_array($snap)?$snap:null];
    }

    private function ensure_area_from_template($a,$workspace_id){
        global $wpdb;
        $workspace_id=absint($workspace_id); $name=sanitize_text_field($a['name']??''); if(!$workspace_id||!$name) return 0;
        $id=(int)$wpdb->get_var($wpdb->prepare("SELECT a.id FROM {$this->tables['work_areas']} a INNER JOIN {$this->tables['workspace_areas']} wa ON wa.work_area_id=a.id WHERE wa.workspace_id=%d AND a.name=%s LIMIT 1",$workspace_id,$name));
        if(!$id){
            $wpdb->insert($this->tables['work_areas'],['name'=>$name,'description'=>sanitize_text_field($a['description']??''),'color'=>sanitize_hex_color($a['color']??'#4f46e5')?:'#4f46e5','status'=>sanitize_key($a['status']??'active'),'stages_json'=>wp_json_encode($this->sanitize_work_area_stages($a['stages_json']??''))]);
            $id=(int)$wpdb->insert_id;
        }
        return $id;
    }

    private function apply_template_to_workspace($workspace_id,$template_id,$create=false,$copy_assignees=true,$copy_dates=true){
        global $wpdb;
        [$tpl,$snap]=$this->get_template_snapshot($template_id);
        if(!$tpl || !$snap) return new WP_Error('bad_template','Workspace Template not found or invalid.');
        $area_ids=[]; $area_by_name=[];
        foreach(($snap['areas']??[]) as $i=>$a){
            $aid=$this->ensure_area_from_template($a,$workspace_id); if(!$aid) continue;
            $area_ids[]=$aid; $area_by_name[$a['name']]=$aid;
            $exists=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['workspace_areas']} WHERE workspace_id=%d AND work_area_id=%d",$workspace_id,$aid));
            if(!$exists) $wpdb->insert($this->tables['workspace_areas'],['workspace_id'=>$workspace_id,'work_area_id'=>$aid,'sort_order'=>(int)($a['sort_order']??$i)]);
        }
        $today=current_time('Y-m-d'); $added=0; $updated=0;
        foreach(($snap['tasks']??[]) as $td){
            $key=sanitize_text_field($td['key']??''); if(!$key) continue;
            $aid=(int)($area_by_name[$td['work_area_name']??'']??0); if(!$aid) continue;
            $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE workspace_id=%d AND template_key=%s LIMIT 1",$workspace_id,$key));
            $is_recurring=($td['task_type']??'One Time')==='Recurring';
            $due_date=null; $start_date=null;
            if((!$create || $copy_dates) && !$is_recurring && $td['due_offset_days']!==null) $due_date=date('Y-m-d',strtotime($today.' '.((int)$td['due_offset_days']>=0?'+':'').((int)$td['due_offset_days']).' days'));
            if($is_recurring){ $off=($create && !$copy_dates)?0:(int)($td['start_offset_days']??0); $start_date=date('Y-m-d',strtotime($today.' '.($off>=0?'+':'').$off.' days')); }
            $assignee=($create && !$copy_assignees)?0:absint($td['assignee_id']??0); if($assignee && !$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['employees']} WHERE id=%d",$assignee))) $assignee=0;
            $data=[
                'task_name'=>sanitize_text_field($td['task_name']??''),'workspace_id'=>$workspace_id,'work_area_id'=>$aid,'assignee_id'=>$assignee?:null,
                'priority'=>$this->normalize_priority($td['priority']??'Medium'),'task_type'=>$is_recurring?'Recurring':'One Time','template_key'=>$key,
                'is_template'=>$is_recurring?1:0,'frequency'=>$is_recurring?sanitize_text_field($td['frequency']??'Daily'):'None',
                'interval_value'=>max(1,absint($td['interval_value']??1)),'interval_unit'=>sanitize_text_field($td['interval_unit']??'Days'),'overdue_policy'=>$is_recurring?(sanitize_key($td['overdue_policy']??'until_next')==='keep'?'keep':'until_next'):'until_next',
                'start_date'=>$start_date,'end_date'=>$is_recurring&&(!$create||$copy_dates)?(sanitize_text_field($td['end_date']??'')?:null):null,
                'due_date'=>$due_date,'due_time'=>sanitize_text_field($td['due_time']??'')?:null,'occurrence_date'=>$due_date,
                'description'=>sanitize_textarea_field($td['description']??''),'labels'=>sanitize_text_field($td['labels']??''),'estimated_minutes'=>absint($td['estimated_minutes']??0),
                'status'=>$is_recurring?'Template':($existing?$existing->status:'Pending'),'updated_at'=>current_time('mysql')
            ];
            if($existing){
                // Never overwrite live completion state/history; only structural definition fields.
                if(!$is_recurring){ unset($data['status']); if($existing->status==='Completed'){ unset($data['due_date'],$data['occurrence_date']); } }
                $wpdb->update($this->tables['tasks'],$data,['id'=>$existing->id]); $task_id=(int)$existing->id; $updated++;
            } else {
                $wpdb->insert($this->tables['tasks'],$data); $task_id=(int)$wpdb->insert_id; $added++;
            }
            $wpdb->delete($this->tables['checklists'],['task_id'=>$task_id]); $wpdb->delete($this->tables['subtask_progress'],['task_id'=>$task_id]);
            foreach(array_values($td['checklist']??[]) as $i=>$text){ $text=sanitize_text_field($text); if($text) $wpdb->insert($this->tables['checklists'],['task_id'=>$task_id,'item_text'=>$text,'sort_order'=>$i]); }
        }
        $wpdb->update($this->tables['workspaces'],['source_template_id'=>$template_id,'template_synced_at'=>current_time('mysql')],['id'=>$workspace_id]);
        return ['added'=>$added,'updated'=>$updated,'areas'=>count($area_ids)];
    }

    private function create_template_version($template_id,$snapshot,$note=''){
        global $wpdb; $template_id=absint($template_id); if(!$template_id || !$snapshot) return 0;
        $next=1+(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(version_number),0) FROM {$this->tables['template_versions']} WHERE template_id=%d",$template_id));
        $wpdb->insert($this->tables['template_versions'],[
            'template_id'=>$template_id,'version_number'=>$next,'snapshot'=>is_string($snapshot)?$snapshot:wp_json_encode($snapshot),
            'note'=>sanitize_text_field($note),'created_by'=>get_current_user_id()?:null,'created_at'=>current_time('mysql')
        ]);
        return (int)$wpdb->insert_id;
    }

    private function template_versions_state(){
        global $wpdb;
        return $wpdb->get_results("SELECT v.id,v.template_id,v.version_number,v.note,v.created_at,u.display_name created_by_name,wt.name template_name
            FROM {$this->tables['template_versions']} v
            LEFT JOIN {$wpdb->users} u ON u.ID=v.created_by
            LEFT JOIN {$this->tables['workspace_templates']} wt ON wt.id=v.template_id
            ORDER BY v.template_id,v.version_number DESC LIMIT 500");
    }

    public function ajax_rollback_template_version(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $version_id=absint($_POST['version_id']??0);
        $v=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['template_versions']} WHERE id=%d",$version_id));
        if(!$v) wp_send_json_error(['message'=>'Template version not found']);
        $tpl=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['workspace_templates']} WHERE id=%d",$v->template_id));
        if(!$tpl) wp_send_json_error(['message'=>'Workspace Template not found']);
        $this->create_template_version($tpl->id,$tpl->snapshot,'Automatic backup before rollback to v'.$v->version_number);
        $wpdb->update($this->tables['workspace_templates'],['snapshot'=>$v->snapshot,'updated_at'=>current_time('mysql')],['id'=>$tpl->id]);
        $this->log_activity('template_rollback',$tpl->name.' rolled back to version '.$v->version_number);
        wp_send_json_success(['template_id'=>(int)$tpl->id,'version'=>(int)$v->version_number]);
    }

    private function work_area_snapshot($work_area_id){
        global $wpdb; $a=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['work_areas']} WHERE id=%d",$work_area_id),ARRAY_A); if(!$a)return null;
        $tasks=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE work_area_id=%d AND archived_at IS NULL ORDER BY sort_order,id",$work_area_id),ARRAY_A);
        foreach($tasks as &$t){
            unset($t['id'],$t['slug'],$t['workspace_id'],$t['work_area_id'],$t['template_id'],$t['created_at'],$t['updated_at'],$t['completed_at'],$t['completed_by_employee_id'],$t['completion_result'],$t['archived_at'],$t['pinned_at']);
            $base=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['tasks']} WHERE work_area_id=%d AND task_name=%s ORDER BY id LIMIT 1",$work_area_id,$t['task_name']));
            $t['checklist']=$base?$wpdb->get_col($wpdb->prepare("SELECT item_text FROM {$this->tables['checklists']} WHERE task_id=%d ORDER BY sort_order,id",$base)):[];
        }
        unset($a['id'],$a['created_at'],$a['head_employee_id']);
        return ['area'=>$a,'tasks'=>$tasks];
    }
    public function ajax_save_work_area_template(){
        $this->verify(); global $wpdb; $aid=absint($_POST['work_area_id']??0); $wid=(int)$wpdb->get_var($wpdb->prepare("SELECT workspace_id FROM {$this->tables['workspace_areas']} WHERE work_area_id=%d LIMIT 1",$aid)); if(!$wid||!$this->workspace_permission($wid,'edit'))wp_send_json_error(['message'=>'Only a Workspace Owner can save templates'],403); $name=sanitize_text_field($_POST['name']??'');
        if(!$aid||!$name) wp_send_json_error(['message'=>'Work Area and template name are required']); $snap=$this->work_area_snapshot($aid); if(!$snap)wp_send_json_error(['message'=>'Work Area not found']);
        $wpdb->insert($this->tables['work_area_templates'],['name'=>$name,'snapshot'=>wp_json_encode($snap),'created_by'=>get_current_user_id()?:null,'updated_at'=>current_time('mysql')]); wp_send_json_success(['template_id'=>(int)$wpdb->insert_id]);
    }
    private function sanitize_work_area_template_snapshot($snap){
        if(!is_array($snap)) return null;
        $area=is_array($snap['area']??null)?$snap['area']:[];
        $clean=['area'=>[
            'name'=>sanitize_text_field($area['name']??''),
            'description'=>sanitize_textarea_field($area['description']??''),
            'color'=>sanitize_hex_color($area['color']??'#4f46e5')?:'#4f46e5',
            'status'=>sanitize_key($area['status']??'active')?:'active',
            'stages_json'=>wp_json_encode($this->sanitize_work_area_stages($area['stages_json']??'')),
        ],'tasks'=>[]];
        foreach(($snap['tasks']??[]) as $t){ if(!is_array($t)) continue; $row=$t;
            $row['task_name']=sanitize_text_field($t['task_name']??'');
            $row['priority']=$this->normalize_priority($t['priority']??'Medium');
            $row['description']=sanitize_textarea_field($t['description']??'');
            $row['due_time']=preg_match('/^\d{2}:\d{2}(?::\d{2})?$/',(string)($t['due_time']??''))?sanitize_text_field($t['due_time']):null;
            $row['frequency']=sanitize_text_field($t['frequency']??'None');
            $row['task_type']=sanitize_text_field($t['task_type']??'One Time');
            $row['checklist']=array_values(array_filter(array_map('sanitize_text_field',is_array($t['checklist']??null)?$t['checklist']:[])));
            if($row['task_name']) $clean['tasks'][]=$row;
        }
        return $clean;
    }
    public function ajax_update_work_area_template(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $id=absint($_POST['id']??0); $name=sanitize_text_field($_POST['name']??''); $snap=json_decode(wp_unslash($_POST['snapshot']??''),true);
        if(!$id||!$name) wp_send_json_error(['message'=>'Template name is required']);
        if(!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['work_area_templates']} WHERE id=%d",$id))) wp_send_json_error(['message'=>'Template not found']);
        $clean=$this->sanitize_work_area_template_snapshot($snap); if(!$clean) wp_send_json_error(['message'=>'Invalid template data']);
        $wpdb->update($this->tables['work_area_templates'],['name'=>$name,'snapshot'=>wp_json_encode($clean),'updated_at'=>current_time('mysql')],['id'=>$id]);
        wp_send_json_success(['template_id'=>$id]);
    }
    public function ajax_create_work_area_from_template(){
        $this->verify(); global $wpdb; $tid=absint($_POST['template_id']??0); $wid=absint($_POST['workspace_id']??0); if(!$this->workspace_permission($wid,'add'))wp_send_json_error(['message'=>'Only a Workspace Owner can create Work Areas'],403); $name=sanitize_text_field($_POST['name']??''); $copy_assignees=!empty($_POST['copy_assignees']); $copy_dates=!empty($_POST['copy_dates']); $position=sanitize_key($_POST['position']??'start');
        $tpl=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['work_area_templates']} WHERE id=%d",$tid)); if(!$tpl)wp_send_json_error(['message'=>'Template not found']); $snap=json_decode($tpl->snapshot,true); if(!$snap)wp_send_json_error(['message'=>'Invalid template']);
        $a=$snap['area']??[]; $a['name']=$name?:($a['name']??$tpl->name); $area_name=sanitize_text_field($a['name']);
        if($this->work_area_name_exists_in_workspace($wid,$area_name,0)) wp_send_json_error(['message'=>'A Work Area with this name already exists in this Workspace.'],422);
        $wpdb->insert($this->tables['work_areas'],['name'=>$area_name,'description'=>'','color'=>sanitize_hex_color($a['color']??'#4f46e5')?:'#4f46e5','status'=>'active','stages_json'=>wp_json_encode($this->sanitize_work_area_stages($a['stages_json']??''))]); $aid=(int)$wpdb->insert_id;
        if($position==='end'){
            $max_sort=$wpdb->get_var($wpdb->prepare("SELECT MAX(sort_order) FROM {$this->tables['workspace_areas']} WHERE workspace_id=%d",$wid));
            $area_sort=($max_sort===null)?0:((int)$max_sort+1);
        }else{
            $wpdb->query($wpdb->prepare("UPDATE {$this->tables['workspace_areas']} SET sort_order=sort_order+1 WHERE workspace_id=%d",$wid));
            $area_sort=0;
        }
        $wpdb->insert($this->tables['workspace_areas'],['workspace_id'=>$wid,'work_area_id'=>$aid,'sort_order'=>$area_sort]);
        foreach(($snap['tasks']??[]) as $td){
            $isr=!empty($td['is_template'])||($td['task_type']??'')==='Recurring'; $due=$copy_dates?sanitize_text_field($td['due_date']??''):null; $start=$copy_dates?sanitize_text_field($td['start_date']??''):current_time('Y-m-d');
            $wpdb->insert($this->tables['tasks'],['task_name'=>sanitize_text_field($td['task_name']??''),'slug'=>$this->unique_task_slug($td['task_name']??'Task'),'workspace_id'=>$wid,'work_area_id'=>$aid,'assignee_id'=>$copy_assignees?absint($td['assignee_id']??0):null,'priority'=>sanitize_text_field($td['priority']??''),'status'=>$isr?'Template':'Pending','task_type'=>$isr?'Recurring':'One Time','is_template'=>$isr?1:0,'frequency'=>$isr?sanitize_text_field($td['frequency']??'Daily'):'None','interval_value'=>max(1,absint($td['interval_value']??1)),'interval_unit'=>sanitize_text_field($td['interval_unit']??'Days'),'start_date'=>$start,'end_date'=>$copy_dates?(sanitize_text_field($td['end_date']??'')?:null):null,'due_date'=>$due?:null,'occurrence_date'=>$due?:null,'description'=>sanitize_textarea_field($td['description']??''),'labels'=>sanitize_text_field($td['labels']??''),'overdue_policy'=>sanitize_key($td['overdue_policy']??'until_next'),'updated_at'=>current_time('mysql')]);
            $task_id=(int)$wpdb->insert_id; foreach(array_values($td['checklist']??[]) as $i=>$text){$text=sanitize_text_field($text);if($text)$wpdb->insert($this->tables['checklists'],['task_id'=>$task_id,'item_text'=>$text,'sort_order'=>$i]);}
        }
        wp_send_json_success(['work_area_id'=>$aid,'workspace_id'=>$wid]);
    }
    public function ajax_delete_work_area_template(){ $this->verify();$this->require_manage();global $wpdb;$wpdb->delete($this->tables['work_area_templates'],['id'=>absint($_POST['id']??0)]);wp_send_json_success(); }

    public function ajax_bulk_archive_tasks(){
        $this->verify(); global $wpdb; $items=json_decode(wp_unslash($_POST['items']??'[]'),true); if(!is_array($items))$items=[]; $n=0;
        foreach($items as $it){$id=absint($it['id']??0);$occ=sanitize_text_field($it['occurrence_date']??''); if(!$id||!$this->task_permission($id,'edit'))continue; $task=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d",$id));if(!$task)continue;if($task->is_template&&$occ){$h=$wpdb->get_row($wpdb->prepare("SELECT id FROM {$this->tables['history']} WHERE task_id=%d AND occurrence_date=%s",$id,$occ));if($h){$wpdb->update($this->tables['history'],['status'=>'Archived','updated_at'=>current_time('mysql')],['id'=>$h->id]);$n++;}}else{$wpdb->update($this->tables['tasks'],['archived_at'=>current_time('mysql')],['id'=>$id]);$n++;}}
        wp_send_json_success(['count'=>$n]);
    }
    public function ajax_bulk_delete_tasks(){
        $this->verify(); global $wpdb; $items=json_decode(wp_unslash($_POST['items']??'[]'),true); if(!is_array($items))$items=[]; $n=0;
        foreach($items as $it){$id=absint($it['id']??0);$occ=sanitize_text_field($it['occurrence_date']??'');if(!$id||!$this->task_permission($id,'edit'))continue;$task=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d",$id));if(!$task)continue;if($task->is_template&&$occ){if($this->delete_recurring_occurrence($task,$occ,false))$n++;}else{$wpdb->delete($this->tables['history'],['task_id'=>$id]);$wpdb->delete($this->tables['recurrence_exceptions'],['task_id'=>$id]);$wpdb->delete($this->tables['checklists'],['task_id'=>$id]);$wpdb->delete($this->tables['subtask_progress'],['task_id'=>$id]);$wpdb->delete($this->tables['task_remarks'],['task_id'=>$id]);$wpdb->delete($this->tables['task_reminders'],['task_id'=>$id]); $wpdb->delete($this->tables['task_overdue_reminders'],['task_id'=>$id]);$wpdb->delete($this->tables['tasks'],['id'=>$id]);$n++;}}
        wp_send_json_success(['count'=>$n]);
    }

    public function ajax_save_workspace_template(){
        $this->verify(); global $wpdb;
        $workspace_id=absint($_POST['workspace_id']??0);
        if(!$workspace_id || !$this->workspace_permission($workspace_id,'edit')) wp_send_json_error(['message'=>'Only a Workspace Owner can save this Workspace as a Template'],403); $name=sanitize_text_field($_POST['name']??''); $description=sanitize_text_field($_POST['description']??'');
        if(!$workspace_id || !$name) wp_send_json_error(['message'=>'Workspace and template name are required']);
        $snap=$this->workspace_snapshot($workspace_id); if(!$snap) wp_send_json_error(['message'=>'Workspace not found']);
        $ok=$wpdb->insert($this->tables['workspace_templates'],['name'=>$name,'description'=>$description,'snapshot'=>wp_json_encode($snap),'status'=>'active','created_by'=>get_current_user_id(),'updated_at'=>current_time('mysql')]);
        if($ok===false) wp_send_json_error(['message'=>'Could not save Workspace Template']);
        $template_id=(int)$wpdb->insert_id;
        if(!empty($_POST['connect'])) $wpdb->update($this->tables['workspaces'],['source_template_id'=>$template_id,'template_synced_at'=>current_time('mysql')],['id'=>$workspace_id]);
        wp_send_json_success(['template_id'=>$template_id]);
    }
    private function sanitize_workspace_template_snapshot($snap){
        if(!is_array($snap)) return null;
        $ws=is_array($snap['workspace']??null)?$snap['workspace']:[];
        $clean=['schema'=>1,'saved_on'=>sanitize_text_field($snap['saved_on']??current_time('Y-m-d')),'workspace'=>[
            'name'=>sanitize_text_field($ws['name']??''),'description'=>sanitize_textarea_field($ws['description']??''),'color'=>sanitize_hex_color($ws['color']??'#4f46e5')?:'#4f46e5'
        ],'areas'=>[],'tasks'=>[]];
        foreach(($snap['areas']??[]) as $a){ if(!is_array($a)) continue; $name=sanitize_text_field($a['name']??''); if(!$name) continue; $clean['areas'][]=[
            'name'=>$name,'description'=>sanitize_textarea_field($a['description']??''),'color'=>sanitize_hex_color($a['color']??'#4f46e5')?:'#4f46e5','status'=>sanitize_key($a['status']??'active')?:'active','stages_json'=>wp_json_encode($this->sanitize_work_area_stages($a['stages_json']??'')),'sort_order'=>(int)($a['sort_order']??0)
        ];}
        foreach(($snap['tasks']??[]) as $t){ if(!is_array($t)) continue; $name=sanitize_text_field($t['task_name']??''); if(!$name) continue; $row=$t;
            $row['task_name']=$name; $row['work_area_name']=sanitize_text_field($t['work_area_name']??''); $row['priority']=$this->normalize_priority($t['priority']??'Medium');
            $row['description']=sanitize_textarea_field($t['description']??''); $row['labels']=sanitize_text_field($t['labels']??''); $row['frequency']=sanitize_text_field($t['frequency']??'None');
            $row['task_type']=sanitize_text_field($t['task_type']??'One Time'); $row['due_time']=preg_match('/^\d{2}:\d{2}(?::\d{2})?$/',(string)($t['due_time']??''))?sanitize_text_field($t['due_time']):null;
            $row['checklist']=array_values(array_filter(array_map('sanitize_text_field',is_array($t['checklist']??null)?$t['checklist']:[])));
            $clean['tasks'][]=$row;
        }
        return $clean;
    }
    public function ajax_update_workspace_template(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $id=absint($_POST['id']??0); $name=sanitize_text_field($_POST['name']??''); $description=sanitize_text_field($_POST['description']??''); $snap=json_decode(wp_unslash($_POST['snapshot']??''),true);
        $tpl=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['workspace_templates']} WHERE id=%d",$id));
        if(!$tpl) wp_send_json_error(['message'=>'Template not found']); if(!$name) wp_send_json_error(['message'=>'Template name is required']);
        $clean=$this->sanitize_workspace_template_snapshot($snap); if(!$clean) wp_send_json_error(['message'=>'Invalid template data']);
        $this->create_template_version($id,$tpl->snapshot,'Automatic backup before template edit');
        $wpdb->update($this->tables['workspace_templates'],['name'=>$name,'description'=>$description,'snapshot'=>wp_json_encode($clean),'updated_at'=>current_time('mysql')],['id'=>$id]);
        wp_send_json_success(['template_id'=>$id]);
    }

    public function ajax_delete_workspace_template(){
        $this->verify(); $this->require_manage(); global $wpdb; $id=absint($_POST['id']??0);
        if($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['workspaces']} WHERE source_template_id=%d",$id))) wp_send_json_error(['message'=>'Detach connected Workspaces before deleting this template.']);
        $wpdb->delete($this->tables['template_versions'],['template_id'=>$id]); $wpdb->delete($this->tables['workspace_templates'],['id'=>$id]); wp_send_json_success();
    }

    public function ajax_create_workspace_from_template(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $template_id=absint($_POST['template_id']??0); $name=sanitize_text_field($_POST['name']??'');
        $folder_ids=$this->posted_folder_ids();
        $assignment_items=$this->assignment_items_from_post('assignments');
        if(!$this->assignment_items_have_valid_workspace_owner($assignment_items)) wp_send_json_error(['message'=>'Please select at least one Workspace Owner.'],422);
        $copy_assignees=!empty($_POST['copy_assignees']); $copy_dates=!empty($_POST['copy_dates']);
        [$tpl,$snap]=$this->get_template_snapshot($template_id); if(!$tpl || !$snap) wp_send_json_error(['message'=>'Workspace Template not found']);
        if(!$name) $name=sanitize_text_field(($snap['workspace']['name']??$tpl->name).' Copy');
        $ok=$wpdb->insert($this->tables['workspaces'],['name'=>$name,'slug'=>$this->unique_workspace_slug($name),'description'=>sanitize_text_field($snap['workspace']['description']??$tpl->description),'color'=>sanitize_hex_color($snap['workspace']['color']??'#4f46e5')?:'#4f46e5','status'=>'active','sort_order'=>$this->next_workspace_sort_order(),'folder_id'=>($folder_ids[0]??null),'source_template_id'=>$template_id,'template_synced_at'=>current_time('mysql')]);
        if($ok===false) wp_send_json_error(['message'=>'Could not create Workspace. The name may already exist.']);
        $wid=(int)$wpdb->insert_id;
        if(!$this->set_workspace_folders($wid,$folder_ids)) wp_send_json_error(['message'=>'Workspace created, but Folder selection could not be updated.'],500);
        $this->save_entity_assignments_internal('workspace',$wid,$assignment_items);
        $result=$this->apply_template_to_workspace($wid,$template_id,true,$copy_assignees,$copy_dates);
        if(is_wp_error($result)) wp_send_json_error(['message'=>$result->get_error_message()]);
        wp_send_json_success(['workspace_id'=>$wid,'result'=>$result]);
    }

    public function ajax_update_connected_template(){
        $this->verify(); $this->require_manage(); global $wpdb; $wid=absint($_POST['workspace_id']??0);
        $ws=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['workspaces']} WHERE id=%d",$wid));
        if(!$ws || !$ws->source_template_id) wp_send_json_error(['message'=>'Workspace is not connected to a template.']);
        $snap=$this->workspace_snapshot($wid); if(!$snap) wp_send_json_error(['message'=>'Could not read Workspace']);
        $current_tpl=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['workspace_templates']} WHERE id=%d",$ws->source_template_id));
        if($current_tpl) $this->create_template_version($current_tpl->id,$current_tpl->snapshot,'Before update from connected Workspace');
        $wpdb->update($this->tables['workspace_templates'],['snapshot'=>wp_json_encode($snap),'updated_at'=>current_time('mysql')],['id'=>$ws->source_template_id]);
        $wpdb->update($this->tables['workspaces'],['template_synced_at'=>current_time('mysql')],['id'=>$wid]);
        $this->log_activity('template_updated_from_workspace','Connected template updated',$wid);
        wp_send_json_success();
    }

    public function ajax_sync_workspace_from_template(){
        $this->verify(); $this->require_manage(); global $wpdb; $wid=absint($_POST['workspace_id']??0);
        $ws=$wpdb->get_row($wpdb->prepare("SELECT source_template_id FROM {$this->tables['workspaces']} WHERE id=%d",$wid));
        if(!$ws || !$ws->source_template_id) wp_send_json_error(['message'=>'Workspace is not connected to a template.']);
        $result=$this->apply_template_to_workspace($wid,(int)$ws->source_template_id,false);
        if(is_wp_error($result)) wp_send_json_error(['message'=>$result->get_error_message()]);
        $this->log_activity('workspace_synced_from_template','Workspace synced from connected template',$wid);
        wp_send_json_success($result);
    }

    public function ajax_detach_workspace_template(){
        $this->verify(); $this->require_manage(); global $wpdb; $wid=absint($_POST['workspace_id']??0);
        $wpdb->update($this->tables['workspaces'],['source_template_id'=>null,'template_synced_at'=>null],['id'=>$wid]); $this->log_activity('template_detached','Workspace detached from template',$wid); wp_send_json_success();
    }

    public function ajax_workspace_template_diff(){
        $this->verify(); global $wpdb; $wid=absint($_POST['workspace_id']??0);
        $ws=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['workspaces']} WHERE id=%d",$wid));
        if(!$ws || !$ws->source_template_id) wp_send_json_error(['message'=>'Workspace is not connected to a template.']);
        [$tpl,$snap]=$this->get_template_snapshot((int)$ws->source_template_id); if(!$snap) wp_send_json_error(['message'=>'Template snapshot is unavailable.']);
        $current=$this->workspace_snapshot($wid); $ta=array_column($snap['areas']??[],null,'name'); $ca=array_column($current['areas']??[],null,'name');
        $tt=array_column($snap['tasks']??[],null,'key'); $ct=array_column($current['tasks']??[],null,'key');
        $area_missing=array_values(array_diff(array_keys($ta),array_keys($ca))); $area_local=array_values(array_diff(array_keys($ca),array_keys($ta)));
        $task_missing=[]; $task_local=[]; $task_changed=[];
        foreach($tt as $k=>$t){ if(!isset($ct[$k])) $task_missing[]=$t['task_name']; else { $a=$t; $b=$ct[$k]; unset($a['due_offset_days'],$a['start_offset_days']); unset($b['due_offset_days'],$b['start_offset_days']); if(wp_json_encode($a)!==wp_json_encode($b)) $task_changed[]=$t['task_name']; } }
        foreach($ct as $k=>$t) if(!isset($tt[$k])) $task_local[]=$t['task_name'];
        wp_send_json_success(['template_name'=>$tpl->name,'area_missing'=>$area_missing,'area_local'=>$area_local,'task_missing'=>$task_missing,'task_local'=>$task_local,'task_changed'=>$task_changed,'synced_at'=>$ws->template_synced_at]);
    }

    private function next_auto_code($table,$column,$prefix,$pad=4){
        global $wpdb;
        $prefix=strtoupper(preg_replace('/[^A-Z0-9]/','',$prefix));
        $rows=$wpdb->get_col("SELECT {$column} FROM {$table} WHERE {$column} LIKE '".esc_sql($prefix)."-%'");
        $max=0;
        foreach($rows as $code){ if(preg_match('/^'.preg_quote($prefix,'/').'-(\d+)$/i',(string)$code,$m)) $max=max($max,(int)$m[1]); }
        return $prefix.'-'.str_pad((string)($max+1),$pad,'0',STR_PAD_LEFT);
    }

    private function save_entity_assignments_internal($entity_type,$entity_id,$items){
        global $wpdb;
        $entity_type=sanitize_key($entity_type); $entity_id=absint($entity_id);
        if(!$entity_id || !in_array($entity_type,['workspace','notespace','note_area','mathspace','math_area','work_area','task','subtask'],true)) return;
        $wpdb->delete($this->tables['entity_assignments'],['entity_type'=>$entity_type,'entity_id'=>$entity_id]);
        if(!is_array($items)) return;
        $seen=[];
        foreach($items as $item){
            $eid=absint($item['employee_id']??0); if(!$eid) continue;
            if(in_array($entity_type,['workspace','note_area','math_area','work_area'],true)){
                if(isset($seen[$eid])) continue;
                $actions=$this->normalize_area_action_rights($item['actions']??($item['rights']??[]),(string)($item['relation_type']??''));
                if(!$actions) continue;
                $seen[$eid]=1;
                $wpdb->insert($this->tables['entity_assignments'],[
                    'entity_type'=>$entity_type,'entity_id'=>$entity_id,'employee_id'=>$eid,
                    'relation_type'=>(in_array('delete',$actions,true)?'owner':'assignee'),'rights'=>implode(',',$actions),'updated_at'=>current_time('mysql')
                ]);
                continue;
            }
            $rel=in_array(($item['relation_type']??''),['owner','assignee'],true)?$item['relation_type']:'assignee';
            $rights=$rel==='owner'?'view_edit':'view';
            $wpdb->insert($this->tables['entity_assignments'],['entity_type'=>$entity_type,'entity_id'=>$entity_id,'employee_id'=>$eid,'relation_type'=>$rel,'rights'=>$rights,'updated_at'=>current_time('mysql')]);
        }
    }

    private function assignment_items_from_post($key){
        $raw=wp_unslash($_POST[$key]??'[]'); $arr=json_decode($raw,true); return is_array($arr)?$arr:[];
    }

    private function assignment_items_have_valid_workspace_owner($items){
        global $wpdb;
        if(!is_array($items)) return false;
        $ids=[];
        foreach($items as $item){
            if(!is_array($item)) continue;
            $raw=$item['actions']??($item['rights']??'');
            $actions=is_array($raw)?$raw:preg_split('/\s*,\s*/',(string)$raw,-1,PREG_SPLIT_NO_EMPTY);
            $is_owner=(($item['relation_type']??'')==='owner') || in_array('delete',(array)$actions,true);
            if(!$is_owner) continue;
            $eid=absint($item['employee_id']??0); if($eid) $ids[]=$eid;
        }
        $ids=array_values(array_unique($ids)); if(!$ids) return false;
        $placeholders=implode(',',array_fill(0,count($ids),'%d'));
        $sql=$wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['employees']} WHERE id IN ($placeholders)",...$ids);
        return (int)$wpdb->get_var($sql)>0;
    }

    private function workspace_has_valid_owner($workspace_id){
        global $wpdb; $workspace_id=absint($workspace_id); if(!$workspace_id) return false;
        return (bool)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tables['entity_assignments']} ea INNER JOIN {$this->tables['employees']} e ON e.id=ea.employee_id WHERE ea.entity_type='workspace' AND ea.entity_id=%d AND ea.relation_type='owner' AND ea.archived_at IS NULL",
            $workspace_id
        ));
    }

    public function ajax_save_entity_assignments(){
        $this->verify(); global $wpdb;
        $type=sanitize_key($_POST['entity_type']??''); $id=absint($_POST['entity_id']??0);
        if(!$id || !in_array($type,['workspace','note_area','math_area','work_area','task','subtask'],true)) wp_send_json_error(['message'=>'Invalid assignment target'],422);
        $can_manage=$this->is_privileged_user() || current_user_can('bb_manage_access');
        $can_entity_share=($type==='task' && $this->task_owner_can_share($id));
        if(!$can_manage && !$can_entity_share) wp_send_json_error(['message'=>'Owner or Manager access required to update sharing'],403);
        $items=$this->assignment_items_from_post('assignments');
        if($type==='workspace' && !$this->assignment_items_have_valid_workspace_owner($items)) wp_send_json_error(['message'=>'Please select at least one Workspace Owner.'],422);
        $before_task=$type==='task'?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d",$id)):null;
        $before_assignees=$type==='task'?$this->task_assignee_employee_ids($id,$before_task?(int)$before_task->assignee_id:0):[];
        $this->save_entity_assignments_internal($type,$id,$items);
        if($type==='task'){
            // v4.13.81: keep the legacy single-assignee column aligned with DIRECT Task
            // assignments. Otherwise a removed assignee can reappear through assignee_id.
            $first_direct_assignee=0;
            foreach($items as $item){
                if(($item['relation_type']??'')==='assignee' && !empty($item['employee_id'])){ $first_direct_assignee=absint($item['employee_id']); break; }
            }
            $wpdb->update($this->tables['tasks'],['assignee_id'=>$first_direct_assignee?:null,'updated_at'=>current_time('mysql')],['id'=>$id]);
            $task=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d",$id));
            $after_assignees=$this->task_assignee_employee_ids($id,$task?(int)$task->assignee_id:0);
            $new_ids=array_values(array_diff($after_assignees,$before_assignees));
            if($new_ids) $this->notify_task_assignment($task,$new_ids);
            // v4.13.80: changing task sharing/Owners/Assignees is a silent edit for Owners.
            // A newly-added assignee still receives the Task Assigned notification above.
        }
        $this->log_activity($type==='task'?'task_shared':'assignments_updated',$type==='task'?'Task sharing / owners / assignees updated':'Owners / assignees updated',0,0,$type==='task'?$id:0);
        wp_send_json_success();
    }

    public function ajax_toggle_task_focus(){
        $this->verify(); global $wpdb;
        $raw=sanitize_text_field($_POST['task_id']??'');
        [$template_id,$occurrence_date]=$this->parse_virtual_id($raw); $task_id=$template_id?:absint($raw);
        if(!$task_id) wp_send_json_error(['message'=>'Task not found'],404);
        $task=$wpdb->get_row($wpdb->prepare("SELECT id,is_focus,focus_auto_date,focus_manual_override_date FROM {$this->tables['tasks']} WHERE id=%d",$task_id));
        if(!$task) wp_send_json_error(['message'=>'Task not found'],404);
        if(!$this->task_permission($task_id,'complete')) wp_send_json_error(['message'=>'You do not have permission to change Focus for this task'],403);
        $next=((int)$task->is_focus) ? 0 : 1;
        $today=current_time('Y-m-d');
        $data=['is_focus'=>$next,'updated_at'=>current_time('mysql')];
        if($next){
            // A user-added Focus is manual. Clear auto metadata so it behaves like the normal toggle.
            $data['focus_auto_date']=null;
            $data['focus_manual_override_date']=null;
        }else{
            $auto_date=(string)($task->focus_auto_date??'');
            if($auto_date!==''){
                // Remember the user's removal against the exact due/recurrence occurrence that
                // caused Auto Focus. This also covers overdue tasks, not only tasks due today.
                $data['focus_manual_override_date']=$auto_date;
            }else{
                $data['focus_auto_date']=null;
                $data['focus_manual_override_date']=null;
            }
        }
        $ok=$wpdb->update($this->tables['tasks'],$data,['id'=>$task_id]);
        if($ok===false) wp_send_json_error(['message'=>'Could not update Focus'],500);
        $this->log_activity($next?'task_focus_added':'task_focus_removed',$next?'Task added to Focus':'Task removed from Focus',(int)($wpdb->get_var($wpdb->prepare("SELECT workspace_id FROM {$this->tables['tasks']} WHERE id=%d",$task_id))?:0),(int)($wpdb->get_var($wpdb->prepare("SELECT work_area_id FROM {$this->tables['tasks']} WHERE id=%d",$task_id))?:0),$task_id);
        wp_send_json_success(['is_focus'=>$next]);
    }

    public function ajax_update_task_field(){
        $this->verify(); global $wpdb;
        $raw=sanitize_text_field($_POST['task_id']??''); [$template_id,$occurrence_date]=$this->parse_virtual_id($raw); $task_id=$template_id?:absint($raw);
        if(!$task_id) wp_send_json_error(['message'=>'Task not found'],404);
        $task=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d",$task_id));
        if(!$task) wp_send_json_error(['message'=>'Task not found'],404);
        if(!$this->task_permission($task_id,'edit')) wp_send_json_error(['message'=>'Only an Owner can edit task details'],403);
        $field=sanitize_key($_POST['field']??''); $value=wp_unslash($_POST['value']??'');
        $allowed=['task_name','priority','due_date','frequency','labels','overdue_policy','show_subtasks'];
        if(!in_array($field,$allowed,true)) wp_send_json_error(['message'=>'This field cannot be edited here'],422);
        $data=['updated_at'=>current_time('mysql')];
        if($field==='task_name'){
            $v=sanitize_text_field($value);
            if(!$v) wp_send_json_error(['message'=>'Task name is required'],422);
            $task_name_length=$this->task_name_length($v);
            if($task_name_length>220) wp_send_json_error(['message'=>'Task name cannot exceed 220 characters. Please reduce by '.($task_name_length-220).' character'.(($task_name_length-220)===1?'':'s').'.'],422);
            $data['task_name']=$v;
        }elseif($field==='priority') $data['priority']=$this->normalize_priority($value);
        elseif($field==='due_date'){
            $v=sanitize_text_field($value);
            $tm=sanitize_text_field(wp_unslash($_POST['due_time']??''));
            if($v && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$v)) wp_send_json_error(['message'=>'Invalid due date'],422);
            if($v && $v < current_time('Y-m-d')) wp_send_json_error(['message'=>'Due Date cannot be earlier than today'],422);
            if($tm && !preg_match('/^\d{2}:\d{2}(?::\d{2})?$/',$tm)) wp_send_json_error(['message'=>'Invalid due time'],422);
            if((int)$task->is_template || $task->task_type==='Recurring'){
                if(!$v) wp_send_json_error(['message'=>'Recurring task date is required'],422);
                $data['start_date']=$v;
                $data['due_date']=null;
                $data['occurrence_date']=null;
                $data['due_time']=$tm?:null;
                if(!empty($task->end_date) && $task->end_date<$v) $data['end_date']=null;
            }else{
                $data['due_date']=$v?:null;
                $data['occurrence_date']=$v?:null;
                $data['due_time']=$v?($tm?:null):null;
            }
        }elseif($field==='labels') $data['labels']=sanitize_text_field($value);
        elseif($field==='show_subtasks') $data['show_subtasks']=!empty($value)?1:0;
        elseif($field==='overdue_policy'){
            if($task->task_type!=='Recurring' && !(int)$task->is_template) wp_send_json_error(['message'=>'This setting is only for recurring tasks'],422);
            $data['overdue_policy']=sanitize_key($value)==='keep'?'keep':'until_next';
        }
        elseif($field==='frequency'){
            $v=sanitize_text_field($value);
            if($v==='' || $v==='None' || $v==='One Time'){
                $data['task_type']='One Time'; $data['is_template']=0; $data['frequency']='None'; $data['start_date']=null; $data['end_date']=null;
                if($task->status==='Template') $data['status']='Pending';
            }else{
                $valid=['Daily','Weekly','Fortnightly','Monthly','Quarterly','Yearly','Custom Interval'];
                if(!in_array($v,$valid,true)) wp_send_json_error(['message'=>'Invalid recurrence'],422);
                $data['task_type']='Recurring'; $data['is_template']=1; $data['frequency']=$v; $data['start_date']=$task->start_date?:($task->due_date?:current_time('Y-m-d')); $data['due_date']=null; $data['occurrence_date']=null; $data['status']='Template'; $data['overdue_policy']=in_array(($task->overdue_policy??'until_next'),['until_next','keep'],true)?$task->overdue_policy:'until_next';
                if($v==='Custom Interval'){
                    $ival=max(1,absint($_POST['interval_value']??($task->interval_value?:1)));
                    $iunit=sanitize_text_field($_POST['interval_unit']??($task->interval_unit?:'Days'));
                    if(!in_array($iunit,['Days','Weeks','Months','Years'],true)) $iunit='Days';
                    $data['interval_value']=$ival; $data['interval_unit']=$iunit;
                }
            }
        }
        $ok=$wpdb->update($this->tables['tasks'],$data,['id'=>$task_id]);
        if($ok===false) wp_send_json_error(['message'=>'Could not update task']);
        $this->log_activity('task_inline_updated','Updated '.$field,$task->workspace_id,$task->work_area_id,$task_id,$template_id?$occurrence_date:null);
        // v4.13.80: inline edits (name, due date, priority, labels, recurrence, etc.)
        // are silent edits and do not send Task Updated to task Owners.
        wp_send_json_success();
    }

    public function ajax_add_inline_subtask(){
        $this->verify(); global $wpdb;
        $raw=sanitize_text_field($_POST['task_id']??''); [$template_id,$occurrence_date]=$this->parse_virtual_id($raw); $task_id=$template_id?:absint($raw);
        $task=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d",$task_id)); if(!$task) wp_send_json_error(['message'=>'Task not found'],404);
        if(!$this->task_permission($task_id,'edit')) wp_send_json_error(['message'=>'Only an Owner can add sub tasks'],403);
        $text=sanitize_text_field($_POST['text']??''); if(!$text) wp_send_json_error(['message'=>'Enter a sub task'],422);
        $sort=(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(sort_order),-1)+1 FROM {$this->tables['checklists']} WHERE task_id=%d",$task_id));
        $wpdb->insert($this->tables['checklists'],['task_id'=>$task_id,'item_text'=>$text,'sort_order'=>$sort,'is_done'=>0]);
        $this->log_activity('subtask_added',$text,$task->workspace_id,$task->work_area_id,$task_id,$template_id?$occurrence_date:null);
        $this->notify_task_owners($task,'Task updated','A sub task was added.');
        wp_send_json_success(['subtask_id'=>$wpdb->insert_id]);
    }

    public function ajax_update_inline_subtask(){
        $this->verify(); global $wpdb;
        $raw=sanitize_text_field($_POST['task_id']??''); [$template_id,$occurrence_date]=$this->parse_virtual_id($raw); $task_id=$template_id?:absint($raw);
        $subtask_id=absint($_POST['subtask_id']??0);
        $text=sanitize_text_field($_POST['text']??'');
        if(!$task_id || !$subtask_id) wp_send_json_error(['message'=>'Sub task not found'],404);
        if(!$text) wp_send_json_error(['message'=>'Enter a sub task'],422);
        $task=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d",$task_id));
        if(!$task) wp_send_json_error(['message'=>'Task not found'],404);
        if(!$this->task_permission($task_id,'edit')) wp_send_json_error(['message'=>'Only an Owner can edit sub tasks'],403);
        $sub=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['checklists']} WHERE id=%d AND task_id=%d",$subtask_id,$task_id));
        if(!$sub) wp_send_json_error(['message'=>'Sub task not found'],404);
        $ok=$wpdb->update($this->tables['checklists'],['item_text'=>$text],['id'=>$subtask_id,'task_id'=>$task_id],['%s'],['%d','%d']);
        if($ok===false) wp_send_json_error(['message'=>'Could not update sub task'],500);
        $this->log_activity('subtask_updated',$text,$task->workspace_id,$task->work_area_id,$task_id,$template_id?$occurrence_date:null);
        $this->notify_task_owners($task,'Task updated','A sub task was updated.');
        wp_send_json_success(['subtask_id'=>$subtask_id,'text'=>$text]);
    }

    public function ajax_reorder_inline_subtasks(){
        $this->verify(); global $wpdb;
        $raw=sanitize_text_field($_POST['task_id']??''); [$template_id,$occurrence_date]=$this->parse_virtual_id($raw); $task_id=$template_id?:absint($raw);
        $task=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d",$task_id));
        if(!$task) wp_send_json_error(['message'=>'Task not found'],404);
        if(!$this->task_permission($task_id,'edit')) wp_send_json_error(['message'=>'Only an Owner can reorder sub tasks'],403);
        $ids_raw=$_POST['subtask_ids']??[];
        if(!is_array($ids_raw)) $ids_raw=explode(',',(string)$ids_raw);
        $ids=array_values(array_unique(array_filter(array_map('absint',$ids_raw))));
        if(!$ids) wp_send_json_error(['message'=>'No sub tasks supplied'],422);
        // Accept the order visible in the open panel.  The client can be a little stale
        // (for example immediately after adding/deleting a sub task), so do not reject
        // the whole reorder merely because another valid row exists in the database.
        $valid=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->tables['checklists']} WHERE task_id=%d ORDER BY sort_order,id",$task_id)));
        $valid_lookup=array_fill_keys($valid,true);
        foreach($ids as $sid){
            if(!isset($valid_lookup[$sid])) wp_send_json_error(['message'=>'Invalid sub task'],422);
        }
        // Keep any rows not present in the submitted panel after the submitted rows.
        $final=$ids;
        foreach($valid as $sid) if(!in_array($sid,$final,true)) $final[]=$sid;
        foreach($final as $i=>$sid){
            $ok=$wpdb->update($this->tables['checklists'],['sort_order'=>$i],['id'=>$sid,'task_id'=>$task_id],['%d'],['%d','%d']);
            if($ok===false) wp_send_json_error(['message'=>'Could not save sub task order'],500);
        }
        $this->log_activity('subtasks_reordered','Sub task order updated',$task->workspace_id,$task->work_area_id,$task_id,$template_id?$occurrence_date:null);
        wp_send_json_success(['order'=>$final]);
    }

    public function ajax_delete_inline_subtask(){
        $this->verify(); global $wpdb;
        $raw=sanitize_text_field($_POST['task_id']??''); [$template_id,$occurrence_date]=$this->parse_virtual_id($raw); $task_id=$template_id?:absint($raw);
        $subtask_id=absint($_POST['subtask_id']??0);
        $task=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d",$task_id));
        if(!$task || !$subtask_id) wp_send_json_error(['message'=>'Sub task not found'],404);
        if(!$this->task_permission($task_id,'edit')) wp_send_json_error(['message'=>'Only an Owner can delete sub tasks'],403);
        $sub=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['checklists']} WHERE id=%d AND task_id=%d",$subtask_id,$task_id));
        if(!$sub) wp_send_json_error(['message'=>'Sub task not found'],404);
        $wpdb->delete($this->tables['entity_assignments'],['entity_type'=>'subtask','entity_id'=>$subtask_id]);
        $wpdb->delete($this->tables['subtask_progress'],['task_id'=>$task_id,'subtask_id'=>$subtask_id]);
        $wpdb->delete($this->tables['checklists'],['id'=>$subtask_id,'task_id'=>$task_id]);
        $this->log_activity('subtask_deleted',$sub->item_text,$task->workspace_id,$task->work_area_id,$task_id,$template_id?$occurrence_date:null);
        $this->notify_task_owners($task,'Task updated','A sub task was deleted.');
        wp_send_json_success();
    }

    public function ajax_add_task_remark(){
        $this->verify(); global $wpdb;
        $raw=sanitize_text_field($_POST['task_id']??''); [$template_id,$occurrence_date]=$this->parse_virtual_id($raw); $task_id=$template_id?:absint($raw);
        $task=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d",$task_id)); if(!$task) wp_send_json_error(['message'=>'Task not found'],404);
        if(!$this->task_permission($task_id,'complete')) wp_send_json_error(['message'=>'You do not have permission to update this task'],403);
        $remark=sanitize_textarea_field($_POST['remark']??''); if(!$remark) wp_send_json_error(['message'=>'Enter a remark'],422);
        $occ=$template_id?$occurrence_date:'one'; $emp=$this->current_employee();
        $created_at=current_time('mysql');
        $wpdb->insert($this->tables['task_remarks'],['task_id'=>$task_id,'occurrence_key'=>$occ,'employee_id'=>$emp?(int)$emp->id:null,'remark'=>$remark,'created_at'=>$created_at]);
        $this->log_activity('remark_added',$remark,$task->workspace_id,$task->work_area_id,$task_id,$template_id?$occurrence_date:null);
        $this->notify_task_owners($task,'Task updated','A progress remark was added.');
        wp_send_json_success([
            'remark_id'=>(int)$wpdb->insert_id,
            'employee_name'=>$emp?($emp->name?:'User'):'User',
            'created_at'=>$created_at
        ]);
    }

    public function ajax_set_task_status(){
        $this->verify(); global $wpdb;
        $raw=sanitize_text_field($_POST['task_id']??''); $status=sanitize_text_field($_POST['status']??'Pending');
        $remark=sanitize_textarea_field($_POST['remark']??'');
        $requested_stage=trim(sanitize_text_field($_POST['stage_name']??''));
        if(!in_array($status,['Pending','In Progress'],true)) wp_send_json_error(['message'=>'Invalid status'],422);
        [$template_id,$occurrence_date]=$this->parse_virtual_id($raw); $task_id=$template_id?:absint($raw);
        $task=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d",$task_id)); if(!$task) wp_send_json_error(['message'=>'Task not found'],404);
        if(!$this->task_permission($task_id,'complete')) wp_send_json_error(['message'=>'You do not have permission to update this task'],403);
        if($this->work_area_update_remarks_required((int)$task->work_area_id) && trim($remark)===''){
            wp_send_json_error(['message'=>'Remarks are required for task status updates in this Work Area.','remark_required'=>1],422);
        }

        $stage_name='';
        if($status==='In Progress'){
            if($requested_stage==='') $requested_stage='In Progress';
            $valid=$this->work_area_has_stage((int)$task->work_area_id,$requested_stage);
            if(!$valid) wp_send_json_error(['message'=>'This stage is not available in the Task Work Area'],422);
            $stage_name=$valid;
        }

        if($template_id){
            $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['history']} WHERE task_id=%d AND occurrence_date=%s",$task_id,$occurrence_date));
            $data=['task_id'=>$task_id,'occurrence_date'=>$occurrence_date,'due_date'=>$occurrence_date,'due_time'=>$task->due_time,'assignee_id'=>$task->assignee_id,'workspace_id'=>$task->workspace_id,'work_area_id'=>$task->work_area_id,'status'=>$status,'stage_name'=>$stage_name,'result'=>'','updated_at'=>current_time('mysql')];
            if($existing) $wpdb->update($this->tables['history'],$data,['id'=>$existing->id]); else $wpdb->insert($this->tables['history'],$data);
        }else{
            $wpdb->update($this->tables['tasks'],['status'=>$status,'stage_name'=>$stage_name,'updated_at'=>current_time('mysql')],['id'=>$task_id]);
        }
        if($remark){
            $occ=$template_id?$occurrence_date:'one';
            $this->save_task_workflow_remark($task_id,$occ,$remark);
        }
        if($stage_name){
            $this->log_activity('task_stage_changed','Stage: '.$stage_name.($remark?' · '.$remark:''),$task->workspace_id,$task->work_area_id,$task_id,$template_id?$occurrence_date:null);
            $this->notify_task_owners($task,'Task stage updated','The task moved to '.$stage_name.'.');
        }else{
            $this->log_activity('task_pending',$task->task_name,$task->workspace_id,$task->work_area_id,$task_id,$template_id?$occurrence_date:null);
            $this->notify_task_owners($task,'Task updated','The task stage was cleared.');
        }
        wp_send_json_success(['stage_name'=>$stage_name,'status'=>$status]);
    }


    private function posted_folder_ids(){
        global $wpdb;
        $ids=[];
        if(isset($_POST['folder_ids'])){
            $raw=wp_unslash($_POST['folder_ids']);
            if(is_array($raw)) $ids=$raw;
            else{
                $decoded=json_decode((string)$raw,true);
                $ids=is_array($decoded)?$decoded:explode(',',sanitize_text_field((string)$raw));
            }
        }elseif(isset($_POST['folder_id']) && absint($_POST['folder_id'])){
            $ids=[absint($_POST['folder_id'])];
        }
        $ids=array_values(array_unique(array_filter(array_map('absint',$ids))));
        if(!$ids) return [];
        $valid=array_map('intval',$wpdb->get_col("SELECT id FROM {$this->tables['folders']} WHERE id IN (".implode(',',array_map('intval',$ids)).")"));
        sort($ids); sort($valid);
        if($ids!==$valid) wp_send_json_error(['message'=>'One or more selected Folders do not exist.'],422);
        return $valid;
    }

    private function sync_workspace_primary_folder($workspace_id){
        global $wpdb;
        $workspace_id=absint($workspace_id);
        if(!$workspace_id) return false;
        $primary=(int)$wpdb->get_var($wpdb->prepare("SELECT folder_id FROM {$this->tables['folder_workspaces']} WHERE workspace_id=%d ORDER BY sort_order ASC,folder_id ASC LIMIT 1",$workspace_id));
        return $wpdb->update($this->tables['workspaces'],['folder_id'=>$primary?:null],['id'=>$workspace_id])!==false;
    }

    private function set_workspace_folders($workspace_id,$folder_ids){
        global $wpdb;
        $workspace_id=absint($workspace_id);
        $folder_ids=array_values(array_unique(array_filter(array_map('absint',(array)$folder_ids))));
        if(!$workspace_id) return false;
        if($wpdb->delete($this->tables['folder_workspaces'],['workspace_id'=>$workspace_id])===false) return false;
        foreach($folder_ids as $i=>$folder_id){
            if($wpdb->insert($this->tables['folder_workspaces'],[
                'folder_id'=>$folder_id,'workspace_id'=>$workspace_id,'sort_order'=>(($i+1)*10),'created_at'=>current_time('mysql')
            ])===false) return false;
        }
        return $this->sync_workspace_primary_folder($workspace_id);
    }

    private function next_folder_sort_order(){
        global $wpdb;
        $max=(int)$wpdb->get_var("SELECT MAX(sort_order) FROM {$this->tables['folders']}");
        return $max+10;
    }

    public function ajax_save_folder(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $id=absint($_POST['id']??0);
        $name=sanitize_text_field($_POST['name']??'');
        $description=sanitize_text_field($_POST['description']??'');
        $color=sanitize_hex_color($_POST['color']??'#4f46e5')?:'#4f46e5';
        if(!$name) wp_send_json_error(['message'=>'Folder name is required'],422);
        $duplicate=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['folders']} WHERE name=%s AND id<>%d LIMIT 1",$name,$id));
        if($duplicate) wp_send_json_error(['message'=>'A Folder with this name already exists.'],409);
        $data=['name'=>$name,'description'=>$description,'color'=>$color,'updated_at'=>current_time('mysql')];
        if(!$id){ $data['sort_order']=$this->next_folder_sort_order(); $data['created_at']=current_time('mysql'); }
        $ok=$id?$wpdb->update($this->tables['folders'],$data,['id'=>$id]):$wpdb->insert($this->tables['folders'],$data);
        if($ok===false) wp_send_json_error(['message'=>'Could not save Folder.'],500);
        $folder_id=$id?:$wpdb->insert_id;
        $this->log_activity($id?'folder_updated':'folder_created',$name);
        wp_send_json_success(['folder_id'=>(int)$folder_id]);
    }

    public function ajax_delete_folder(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $id=absint($_POST['id']??0);
        $folder=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['folders']} WHERE id=%d",$id));
        if(!$folder) wp_send_json_error(['message'=>'Folder not found'],404);
        $affected=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT workspace_id FROM {$this->tables['folder_workspaces']} WHERE folder_id=%d",$id)));
        $wpdb->query('START TRANSACTION');
        if($wpdb->delete($this->tables['folder_workspaces'],['folder_id'=>$id])===false
            || $wpdb->delete($this->tables['folder_notespaces'],['folder_id'=>$id])===false
            || $wpdb->delete($this->tables['folders'],['id'=>$id])===false){
            $wpdb->query('ROLLBACK'); wp_send_json_error(['message'=>'Could not delete Folder.'],500);
        }
        foreach($affected as $wid){
            if(!$this->sync_workspace_primary_folder($wid)){ $wpdb->query('ROLLBACK'); wp_send_json_error(['message'=>'Could not update Workspace Folder links.'],500); }
        }
        $wpdb->query('COMMIT');
        $this->log_activity('folder_deleted',$folder->name);
        wp_send_json_success(['message'=>'Folder deleted. Workspace data remains unchanged.']);
    }

    public function ajax_assign_folder_workspaces(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $folder_id=absint($_POST['folder_id']??0);
        if(!$folder_id || !$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['folders']} WHERE id=%d",$folder_id))) wp_send_json_error(['message'=>'Folder not found'],404);
        $raw=wp_unslash($_POST['workspace_ids']??'[]');
        $ids=json_decode($raw,true); if(!is_array($ids)) $ids=[];
        $ids=array_values(array_unique(array_filter(array_map('absint',$ids))));
        $valid=[];
        if($ids){ $valid=array_map('intval',$wpdb->get_col("SELECT id FROM {$this->tables['workspaces']} WHERE status NOT IN ('trash','archived') AND id IN (".implode(',',array_map('intval',$ids)).")")); }
        $old=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT workspace_id FROM {$this->tables['folder_workspaces']} WHERE folder_id=%d",$folder_id)));
        $affected=array_values(array_unique(array_merge($old,$valid)));
        $wpdb->query('START TRANSACTION');
        if($wpdb->delete($this->tables['folder_workspaces'],['folder_id'=>$folder_id])===false){ $wpdb->query('ROLLBACK'); wp_send_json_error(['message'=>'Could not update Folder Workspaces'],500); }
        foreach($valid as $i=>$wid){
            if($wpdb->insert($this->tables['folder_workspaces'],['folder_id'=>$folder_id,'workspace_id'=>$wid,'sort_order'=>(($i+1)*10),'created_at'=>current_time('mysql')])===false){
                $wpdb->query('ROLLBACK'); wp_send_json_error(['message'=>'Could not update Folder Workspaces'],500);
            }
        }
        foreach($affected as $wid){
            if(!$this->sync_workspace_primary_folder($wid)){ $wpdb->query('ROLLBACK'); wp_send_json_error(['message'=>'Could not update Workspace Folder links'],500); }
        }
        $wpdb->query('COMMIT');
        wp_send_json_success(['workspace_ids'=>$valid]);
    }

    private function next_workspace_sort_order(){
        global $wpdb;
        $max=(int)$wpdb->get_var("SELECT MAX(sort_order) FROM {$this->tables['workspaces']}");
        return $max+10;
    }

    public function ajax_reorder_workspaces(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $raw=wp_unslash($_POST['workspace_ids']??'[]');
        $ids=json_decode($raw,true);
        if(!is_array($ids)) $ids=array_filter(array_map('absint',explode(',',sanitize_text_field($raw))));
        $ids=array_values(array_unique(array_filter(array_map('absint',$ids))));
        if(!$ids) wp_send_json_error(['message'=>'No Workspaces supplied']);
        $valid=array_map('intval',$wpdb->get_col("SELECT id FROM {$this->tables['workspaces']} WHERE status NOT IN ('trash','archived')"));
        $validSet=array_fill_keys($valid,true);
        foreach($ids as $id) if(!isset($validSet[$id])) wp_send_json_error(['message'=>'Invalid Workspace order'],400);
        $wpdb->query('START TRANSACTION');
        foreach($ids as $i=>$id){
            if($wpdb->update($this->tables['workspaces'],['sort_order'=>(($i+1)*10)],['id'=>$id])===false){
                $wpdb->query('ROLLBACK');
                wp_send_json_error(['message'=>'Could not save Workspace order'],500);
            }
        }
        $wpdb->query('COMMIT');
        wp_send_json_success(['workspace_ids'=>$ids]);
    }

    public function ajax_save_workspace(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $id=absint($_POST['id']??0);
        $has_assignments=isset($_POST['assignments']);
        $assignment_items=$has_assignments?$this->assignment_items_from_post('assignments'):[];
        $folder_ids=$this->posted_folder_ids();
        $data=['name'=>sanitize_text_field($_POST['name']??''),'description'=>sanitize_text_field($_POST['description']??''),'status'=>sanitize_key($_POST['status']??'active'),'color'=>sanitize_hex_color($_POST['color']??'#4f46e5')?:'#4f46e5','folder_id'=>($folder_ids[0]??null)];
        if(!$data['name']) wp_send_json_error(['message'=>'Workspace name is required']);
        $duplicate_id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['workspaces']} WHERE LOWER(TRIM(name))=LOWER(TRIM(%s)) AND id<>%d LIMIT 1",$data['name'],$id));
        if($duplicate_id) wp_send_json_error(['message'=>'A Workspace with this name already exists.'],422);
        $requested_slug=sanitize_title($_POST['slug']??'');
        // Keep an existing Workspace URL stable when its display name changes.
        $existing_slug=$id?(string)$wpdb->get_var($wpdb->prepare("SELECT slug FROM {$this->tables['workspaces']} WHERE id=%d",$id)):'';
        $data['slug']=$existing_slug?:$this->unique_workspace_slug($requested_slug?:$data['name'],$id);
        if(!$id)$data['sort_order']=$this->next_workspace_sort_order();
        $ok=$id?$wpdb->update($this->tables['workspaces'],$data,['id'=>$id]):$wpdb->insert($this->tables['workspaces'],$data);
        if($ok===false) wp_send_json_error(['message'=>'Could not save workspace. It may already exist.']);
        $workspace_id=$id?:$wpdb->insert_id;
        if(!$this->set_workspace_folders($workspace_id,$folder_ids)) wp_send_json_error(['message'=>'Workspace saved, but Folder selection could not be updated.'],500);
        if($has_assignments) $this->save_entity_assignments_internal('workspace',$workspace_id,$assignment_items);
        if(get_option('bb_enable_party_share',0)){
            $party_assignments=json_decode(wp_unslash($_POST['party_assignments']??'[]'),true); if(!is_array($party_assignments))$party_assignments=[];
            $wpdb->delete($this->tables['workspace_parties'],['workspace_id'=>$workspace_id]);
            foreach($party_assignments as $pa){
                $pid=absint($pa['party_id']??0); if(!$pid)continue;
                $rights=$this->normalize_area_action_rights($pa['rights']??'view');
                $rights=implode(',',array_values(array_intersect(['add','edit','delete','view'],$rights))); if(!$rights)$rights='view';
                if($wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['parties']} WHERE id=%d AND status='active'",$pid)))
                    $wpdb->insert($this->tables['workspace_parties'],['workspace_id'=>$workspace_id,'party_id'=>$pid,'rights'=>$rights,'created_at'=>current_time('mysql')]);
            }
        }
        // Work Areas are managed inside the Workspace. Do not detach them when Workspace details are edited.
        if(isset($_POST['work_area_ids'])){
            $area_ids=array_filter(array_map('absint',explode(',',sanitize_text_field($_POST['work_area_ids']??''))));
            $wpdb->delete($this->tables['workspace_areas'],['workspace_id'=>$workspace_id]);
            foreach(array_values($area_ids) as $i=>$aid) $wpdb->insert($this->tables['workspace_areas'],['workspace_id'=>$workspace_id,'work_area_id'=>$aid,'sort_order'=>$i]);
        }
        $this->log_activity($id?'workspace_updated':'workspace_created',$data['name'],$workspace_id);
        wp_send_json_success(['workspace_id'=>$workspace_id]);
    }
    public function ajax_archive_workspace(){
        $this->verify(); $this->require_manage(); global $wpdb; $id=absint($_POST['id']??0);
        $ws=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['workspaces']} WHERE id=%d",$id));
        if(!$ws) wp_send_json_error(['message'=>'Workspace not found'],404);
        if($ws->status==='trash') wp_send_json_error(['message'=>'Restore the Workspace from Trash before archiving it'],409);
        if($ws->status==='archived') wp_send_json_success(['message'=>'Workspace is already archived']);
        $wpdb->update($this->tables['workspaces'],['status'=>'archived','trash_previous_status'=>null],['id'=>$id]);
        $this->log_activity('workspace_archived',$ws->name,$id);
        wp_send_json_success(['message'=>'Workspace archived']);
    }
    public function ajax_unarchive_workspace(){
        $this->verify(); $this->require_manage(); global $wpdb; $id=absint($_POST['id']??0);
        $ws=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['workspaces']} WHERE id=%d AND status='archived'",$id));
        if(!$ws) wp_send_json_error(['message'=>'Workspace not found in Archive'],404);
        $wpdb->update($this->tables['workspaces'],['status'=>'active','trash_previous_status'=>null],['id'=>$id]);
        $this->log_activity('workspace_unarchived',$ws->name,$id);
        wp_send_json_success(['message'=>'Workspace unarchived']);
    }
    public function ajax_delete_workspace(){
        $this->verify(); $this->require_manage(); global $wpdb; $id=absint($_POST['id']??0);
        $ws=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['workspaces']} WHERE id=%d",$id));
        if(!$ws) wp_send_json_error(['message'=>'Workspace not found'],404);
        if($ws->status==='trash') wp_send_json_success(['message'=>'Workspace is already in Trash']);
        $previous=in_array($ws->status,['active','inactive','archived'],true)?$ws->status:'active';
        $wpdb->update($this->tables['workspaces'],['status'=>'trash','trash_previous_status'=>$previous],['id'=>$id]);
        $this->log_activity('workspace_trashed',$ws->name,$id);
        wp_send_json_success(['message'=>'Workspace moved to Trash']);
    }
    public function ajax_restore_workspace(){
        $this->verify(); $this->require_manage(); global $wpdb; $id=absint($_POST['id']??0);
        $ws=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['workspaces']} WHERE id=%d AND status='trash'",$id));
        if(!$ws) wp_send_json_error(['message'=>'Workspace not found in Trash'],404);
        $restore=in_array((string)$ws->trash_previous_status,['active','inactive','archived'],true)?(string)$ws->trash_previous_status:'active';
        $wpdb->update($this->tables['workspaces'],['status'=>$restore,'trash_previous_status'=>null],['id'=>$id]);
        $this->log_activity('workspace_restored',$ws->name,$id);
        wp_send_json_success(['message'=>'Workspace restored','status'=>$restore]);
    }
    public function ajax_permanent_delete_workspace(){
        $this->verify(); $this->require_manage(); global $wpdb; $id=absint($_POST['id']??0);
        $ws=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['workspaces']} WHERE id=%d AND status='trash'",$id));
        if(!$ws) wp_send_json_error(['message'=>'Only a Workspace in Trash can be permanently deleted'],409);
        $task_ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->tables['tasks']} WHERE workspace_id=%d",$id)));
        foreach($task_ids as $tid){
            $check_ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->tables['checklists']} WHERE task_id=%d",$tid)));
            foreach($check_ids as $cid) $wpdb->delete($this->tables['entity_assignments'],['entity_type'=>'subtask','entity_id'=>$cid]);
            $wpdb->delete($this->tables['history'],['task_id'=>$tid]);
            $wpdb->delete($this->tables['score_archive'],['task_id'=>$tid]);
            $wpdb->delete($this->tables['subtask_progress'],['task_id'=>$tid]);
            $wpdb->delete($this->tables['checklists'],['task_id'=>$tid]);
            $wpdb->delete($this->tables['task_remarks'],['task_id'=>$tid]);
            $wpdb->delete($this->tables['entity_assignments'],['entity_type'=>'task','entity_id'=>$tid]);
            if(isset($this->tables['task_attachments'])) $wpdb->delete($this->tables['task_attachments'],['task_id'=>$tid]);
        }
        $wpdb->delete($this->tables['tasks'],['workspace_id'=>$id]);
        $area_ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT work_area_id FROM {$this->tables['workspace_areas']} WHERE workspace_id=%d",$id)));
        foreach($area_ids as $aid){
            $wpdb->delete($this->tables['entity_assignments'],['entity_type'=>'work_area','entity_id'=>$aid]);
            $wpdb->delete($this->tables['work_areas'],['id'=>$aid]);
        }
        $wpdb->delete($this->tables['workspace_areas'],['workspace_id'=>$id]);
        $wpdb->delete($this->tables['workspace_departments'],['workspace_id'=>$id]);
        $wpdb->delete($this->tables['workspace_access'],['workspace_id'=>$id]); $wpdb->delete($this->tables['workspace_parties'],['workspace_id'=>$id]);
        $wpdb->delete($this->tables['folder_workspaces'],['workspace_id'=>$id]);
        $wpdb->delete($this->tables['entity_assignments'],['entity_type'=>'workspace','entity_id'=>$id]);
        $wpdb->delete($this->tables['activity'],['workspace_id'=>$id]);
        $wpdb->delete($this->tables['workspaces'],['id'=>$id]);
        wp_send_json_success(['message'=>'Workspace permanently deleted']);
    }

    private function normalized_work_area_name($name){
        $name=trim((string)$name);
        $name=preg_replace('/\s+/u',' ',$name);
        return function_exists('mb_strtolower')?mb_strtolower($name,'UTF-8'):strtolower($name);
    }
    private function work_area_name_exists_in_workspace($workspace_id,$name,$exclude_id=0){
        global $wpdb;
        $workspace_id=absint($workspace_id); $exclude_id=absint($exclude_id); $needle=$this->normalized_work_area_name($name);
        if(!$workspace_id || $needle==='') return false;
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT wa.id,wa.name FROM {$this->tables['workspace_areas']} x INNER JOIN {$this->tables['work_areas']} wa ON wa.id=x.work_area_id WHERE x.workspace_id=%d",
            $workspace_id
        ));
        foreach($rows as $row){
            if($exclude_id && (int)$row->id===$exclude_id) continue;
            if($this->normalized_work_area_name($row->name)===$needle) return true;
        }
        return false;
    }

    public function ajax_save_work_area(){
        $this->verify(); global $wpdb; $id=absint($_POST['id']??0); $workspace_id=absint($_POST['workspace_id']??0);
        if(!$this->workspace_permission($workspace_id,'edit')) wp_send_json_error(['message'=>'Only a Workspace Owner can manage Work Areas'],403);
        if(!$workspace_id || !$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['workspaces']} WHERE id=%d",$workspace_id))) wp_send_json_error(['message'=>'A valid Workspace is required. Work Areas can only be created inside a Workspace.']);
        $wa_assignments=isset($_POST['assignments'])?$this->assignment_items_from_post('assignments'):[]; $legacy_head=0;
        foreach($wa_assignments as $ai){ if(in_array(($ai['relation_type']??''),['owner','assignee'],true) && !empty($ai['employee_id'])){ $legacy_head=absint($ai['employee_id']); break; } }
        $stages=$this->register_work_area_stages_in_master($this->sanitize_work_area_stages($_POST['stages_json']??''));
        $data=['name'=>sanitize_text_field($_POST['name']??''),'description'=>sanitize_text_field($_POST['description']??''),'head_employee_id'=>$legacy_head?:null,'status'=>sanitize_key($_POST['status']??'active'),'color'=>sanitize_hex_color($_POST['color']??'#4f46e5')?:'#4f46e5','stages_json'=>wp_json_encode($stages),'update_remarks_required'=>!empty($_POST['update_remarks_required'])?1:0,'remarks_view_days_limit'=>min(3650,max(0,absint($_POST['remarks_view_days_limit']??0)))];
        if(!$data['name']) wp_send_json_error(['message'=>'Work Area name is required']);
        if($this->work_area_name_exists_in_workspace($workspace_id,$data['name'],$id)) wp_send_json_error(['message'=>'A Work Area with this name already exists in this Workspace.'],422);
        $ok=$id?$wpdb->update($this->tables['work_areas'],$data,['id'=>$id]):$wpdb->insert($this->tables['work_areas'],$data);
        if($ok===false) wp_send_json_error(['message'=>'Could not save Work Area.']);
        $aid=$id?:$wpdb->insert_id;
        if($id){
            $valid_stage_names=array_map(fn($x)=>(string)$x['name'],$stages);
            $stage_rows=$wpdb->get_results($wpdb->prepare("SELECT id,stage_name FROM {$this->tables['tasks']} WHERE work_area_id=%d AND stage_name<>''",$aid));
            foreach($stage_rows as $tr){
                $keep=false; foreach($valid_stage_names as $sn){ if(strcasecmp($sn,(string)$tr->stage_name)===0){$keep=true;break;} }
                if(!$keep) $wpdb->update($this->tables['tasks'],['stage_name'=>'','status'=>'Pending','updated_at'=>current_time('mysql')],['id'=>(int)$tr->id]);
            }
            $hist_rows=$wpdb->get_results($wpdb->prepare("SELECT id,stage_name FROM {$this->tables['history']} WHERE work_area_id=%d AND stage_name<>''",$aid));
            foreach($hist_rows as $hr){
                $keep=false; foreach($valid_stage_names as $sn){ if(strcasecmp($sn,(string)$hr->stage_name)===0){$keep=true;break;} }
                if(!$keep) $wpdb->update($this->tables['history'],['stage_name'=>'','status'=>'Pending','updated_at'=>current_time('mysql')],['id'=>(int)$hr->id]);
            }
        }
        if(isset($_POST['assignments'])) $this->save_entity_assignments_internal('work_area',$aid,$wa_assignments);
        if(get_option('bb_enable_party_share',0)){ $party_assignments=json_decode(wp_unslash($_POST['party_assignments']??'[]'),true); if(!is_array($party_assignments))$party_assignments=[]; $wpdb->delete($this->tables['work_area_parties'],['work_area_id'=>$aid]); foreach($party_assignments as $pa){ $pid=absint($pa['party_id']??0); if(!$pid)continue; $rights=$this->normalize_area_action_rights($pa['rights']??'view'); $rights=implode(',',array_values(array_intersect(['add','edit','delete','view'],$rights))); if(!$rights)$rights='view'; if($wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['parties']} WHERE id=%d AND status='active'",$pid))) $wpdb->insert($this->tables['work_area_parties'],['work_area_id'=>$aid,'party_id'=>$pid,'rights'=>$rights,'created_at'=>current_time('mysql')]); } }
        // A Work Area belongs to exactly one Workspace. Preserve its current order when editing in the same Workspace.
        $existing_map=$wpdb->get_row($wpdb->prepare("SELECT workspace_id,sort_order FROM {$this->tables['workspace_areas']} WHERE work_area_id=%d LIMIT 1",$aid));
        $keep_sort=($existing_map && (int)$existing_map->workspace_id===$workspace_id)?(int)$existing_map->sort_order:null;
        $wpdb->delete($this->tables['workspace_areas'],['work_area_id'=>$aid]);
        if($keep_sort!==null){
            $sort=$keep_sort;
        }else{
            $raw_insert=isset($_POST['insert_index'])?trim((string)wp_unslash($_POST['insert_index'])):'';
            $position=sanitize_key($_POST['position']??'start');
            if($raw_insert!=='' && ctype_digit($raw_insert)){
                $requested=max(0,(int)$raw_insert);
            }elseif($position==='end'){
                $max_sort=$wpdb->get_var($wpdb->prepare("SELECT MAX(sort_order) FROM {$this->tables['workspace_areas']} WHERE workspace_id=%d",$workspace_id));
                $requested=($max_sort===null)?0:((int)$max_sort+1);
            }else{
                $requested=0;
            }
            $wpdb->query($wpdb->prepare("UPDATE {$this->tables['workspace_areas']} SET sort_order=sort_order+1 WHERE workspace_id=%d AND sort_order >= %d",$workspace_id,$requested));
            $sort=$requested;
        }
        $mapped=$wpdb->insert($this->tables['workspace_areas'],['workspace_id'=>$workspace_id,'work_area_id'=>$aid,'sort_order'=>$sort]);
        if($mapped===false) wp_send_json_error(['message'=>'Work Area was saved, but could not be attached to the Workspace. Please try again.']);
        $this->log_activity($id?'work_area_updated':'work_area_created',$data['name'],$workspace_id,$aid); wp_send_json_success(['workspace_id'=>$workspace_id,'work_area_id'=>$aid]);
    }
    private function work_area_workspace_map($id){
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT workspace_id,work_area_id,sort_order FROM {$this->tables['workspace_areas']} WHERE work_area_id=%d LIMIT 1",absint($id)));
    }

    private function unique_work_area_copy_name($workspace_id,$base){
        global $wpdb;
        $base=trim((string)$base); if($base==='') $base='Work Area';
        $candidate=$base.' - Copy'; $n=2;
        while($wpdb->get_var($wpdb->prepare("SELECT a.id FROM {$this->tables['work_areas']} a JOIN {$this->tables['workspace_areas']} wa ON wa.work_area_id=a.id WHERE wa.workspace_id=%d AND LOWER(a.name)=LOWER(%s) LIMIT 1",$workspace_id,$candidate))){
            $candidate=$base.' - Copy '.$n; $n++;
        }
        return $candidate;
    }

    public function ajax_duplicate_work_area(){
        $this->verify(); global $wpdb; $id=absint($_POST['id']??0);
        $map=$this->work_area_workspace_map($id); if(!$map) wp_send_json_error(['message'=>'Work Area not found'],404);
        $wid=(int)$map->workspace_id;
        if(!$this->workspace_permission($wid,'edit')) wp_send_json_error(['message'=>'Only a Workspace Owner can copy this Work Area'],403);
        $wa=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['work_areas']} WHERE id=%d",$id));
        if(!$wa || in_array((string)$wa->status,['trash','archived'],true)) wp_send_json_error(['message'=>'Only an active Work Area can be copied'],422);

        $wpdb->query('START TRANSACTION');
        $now=current_time('mysql');
        $name=$this->unique_work_area_copy_name($wid,$wa->name);
        $ok=$wpdb->insert($this->tables['work_areas'],[
            'name'=>$name,'description'=>$wa->description,'head_employee_id'=>$wa->head_employee_id?:null,
            'color'=>$wa->color?:'#4f46e5','status'=>'active','stages_json'=>$wa->stages_json,'update_remarks_required'=>(int)($wa->update_remarks_required??0),'remarks_view_days_limit'=>(int)($wa->remarks_view_days_limit??0),'created_at'=>$now
        ]);
        if($ok===false){ $wpdb->query('ROLLBACK'); wp_send_json_error(['message'=>'Could not copy Work Area'],500); }
        $new_area=(int)$wpdb->insert_id;
        $insert=(int)$map->sort_order+1;
        if($wpdb->query($wpdb->prepare("UPDATE {$this->tables['workspace_areas']} SET sort_order=sort_order+1 WHERE workspace_id=%d AND sort_order >= %d",$wid,$insert))===false){ $wpdb->query('ROLLBACK'); wp_send_json_error(['message'=>'Could not position copied Work Area'],500); }
        if($wpdb->insert($this->tables['workspace_areas'],['workspace_id'=>$wid,'work_area_id'=>$new_area,'sort_order'=>$insert])===false){ $wpdb->query('ROLLBACK'); wp_send_json_error(['message'=>'Could not attach copied Work Area'],500); }

        // Copy Work Area Owners / Assignees.
        $area_assign=$wpdb->get_results($wpdb->prepare("SELECT employee_id,relation_type,rights FROM {$this->tables['entity_assignments']} WHERE entity_type='work_area' AND entity_id=%d AND archived_at IS NULL ORDER BY id",$id),ARRAY_A);
        foreach($area_assign as $a){
            $wpdb->insert($this->tables['entity_assignments'],['entity_type'=>'work_area','entity_id'=>$new_area,'employee_id'=>(int)$a['employee_id'],'relation_type'=>$a['relation_type'],'rights'=>$a['rights'],'created_at'=>$now,'updated_at'=>$now]);
        }

        // Copy active task definitions only. History, completed rows, remarks, proof files and activity are intentionally excluded.
        $tasks=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE workspace_id=%d AND work_area_id=%d AND archived_at IS NULL AND status NOT IN ('Completed','Cancelled') ORDER BY sort_order,id",$wid,$id));
        $task_count=0;
        foreach($tasks as $task){
            $copy=(array)$task; unset($copy['id']);
            $copy['workspace_id']=$wid; $copy['work_area_id']=$new_area;
            $copy['slug']=$this->unique_task_slug($task->task_name,0);
            $copy['template_id']=null;
            $copy['template_key']=$task->is_template?('wa_copy_'.wp_generate_uuid4()):'';
            $copy['status']=$task->is_template?'Template':'Pending';
            $copy['completion_result']=''; $copy['completed_by_employee_id']=null; $copy['completed_at']=null;
            $copy['archived_at']=null; $copy['pinned_at']=null; $copy['is_focus']=0; $copy['focus_auto_date']=null; $copy['focus_manual_override_date']=null;
            $copy['created_at']=$now; $copy['updated_at']=$now;
            if($wpdb->insert($this->tables['tasks'],$copy)===false){ $wpdb->query('ROLLBACK'); wp_send_json_error(['message'=>'Could not copy Work Area tasks'],500); }
            $new_task=(int)$wpdb->insert_id; $task_count++;

            $task_assign=$wpdb->get_results($wpdb->prepare("SELECT employee_id,relation_type,rights FROM {$this->tables['entity_assignments']} WHERE entity_type='task' AND entity_id=%d AND archived_at IS NULL ORDER BY id",$task->id),ARRAY_A);
            foreach($task_assign as $a){
                $wpdb->insert($this->tables['entity_assignments'],['entity_type'=>'task','entity_id'=>$new_task,'employee_id'=>(int)$a['employee_id'],'relation_type'=>$a['relation_type'],'rights'=>$a['rights'],'created_at'=>$now,'updated_at'=>$now]);
            }
            foreach($this->additional_task_reminder_rules((int)$task->id) as $rr){
                $wpdb->insert($this->tables['task_reminders'],['task_id'=>$new_task,'reminder_value'=>(int)$rr->reminder_value,'reminder_unit'=>$rr->reminder_unit,'sort_order'=>(int)$rr->sort_order,'created_at'=>$now,'updated_at'=>$now]);
            }
            $subs=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tables['checklists']} WHERE task_id=%d ORDER BY sort_order,id",$task->id));
            foreach($subs as $sub){
                if($wpdb->insert($this->tables['checklists'],['task_id'=>$new_task,'item_text'=>$sub->item_text,'sort_order'=>(int)$sub->sort_order,'is_done'=>0])===false){ $wpdb->query('ROLLBACK'); wp_send_json_error(['message'=>'Could not copy Work Area sub tasks'],500); }
                $new_sub=(int)$wpdb->insert_id;
                $sub_assign=$wpdb->get_results($wpdb->prepare("SELECT employee_id,relation_type,rights FROM {$this->tables['entity_assignments']} WHERE entity_type='subtask' AND entity_id=%d AND archived_at IS NULL ORDER BY id",$sub->id),ARRAY_A);
                foreach($sub_assign as $a){
                    $wpdb->insert($this->tables['entity_assignments'],['entity_type'=>'subtask','entity_id'=>$new_sub,'employee_id'=>(int)$a['employee_id'],'relation_type'=>$a['relation_type'],'rights'=>$a['rights'],'created_at'=>$now,'updated_at'=>$now]);
                }
            }
        }
        $wpdb->query('COMMIT');
        $this->log_activity('work_area_copied',$wa->name.' → '.$name,$wid,$new_area);
        wp_send_json_success(['workspace_id'=>$wid,'work_area_id'=>$new_area,'name'=>$name,'task_count'=>$task_count]);
    }

    public function ajax_archive_work_area(){
        $this->verify(); global $wpdb; $id=absint($_POST['id']??0); $map=$this->work_area_workspace_map($id);
        if(!$map || !$this->workspace_permission((int)$map->workspace_id,'edit')) wp_send_json_error(['message'=>'Only a Workspace Owner can archive this Work Area'],403);
        $wpdb->update($this->tables['work_areas'],['status'=>'archived'],['id'=>$id]);
        $this->log_activity('work_area_archived','Work Area archived',(int)$map->workspace_id,$id);
        wp_send_json_success(['work_area_id'=>$id]);
    }

    public function ajax_unarchive_work_area(){
        $this->verify(); global $wpdb; $id=absint($_POST['id']??0); $map=$this->work_area_workspace_map($id);
        if(!$map || !$this->workspace_permission((int)$map->workspace_id,'edit')) wp_send_json_error(['message'=>'Only a Workspace Owner can restore this Work Area'],403);
        $wpdb->update($this->tables['work_areas'],['status'=>'active'],['id'=>$id]);
        $this->log_activity('work_area_unarchived','Work Area restored from Archive',(int)$map->workspace_id,$id);
        wp_send_json_success(['work_area_id'=>$id]);
    }

    public function ajax_delete_work_area(){
        $this->verify(); global $wpdb; $id=absint($_POST['id']??0); $map=$this->work_area_workspace_map($id);
        if(!$map || !$this->workspace_permission((int)$map->workspace_id,'delete')) wp_send_json_error(['message'=>'Only a Workspace Owner can move this Work Area to Trash'],403);
        $wpdb->update($this->tables['work_areas'],['status'=>'trash'],['id'=>$id]);
        $this->log_activity('work_area_trashed','Work Area moved to Trash',(int)$map->workspace_id,$id);
        wp_send_json_success(['work_area_id'=>$id]);
    }

    public function ajax_restore_work_area(){
        $this->verify(); global $wpdb; $id=absint($_POST['id']??0); $map=$this->work_area_workspace_map($id);
        if(!$map || !$this->workspace_permission((int)$map->workspace_id,'edit')) wp_send_json_error(['message'=>'Only a Workspace Owner can restore this Work Area'],403);
        $wpdb->update($this->tables['work_areas'],['status'=>'active'],['id'=>$id]);
        $this->log_activity('work_area_restored','Work Area restored from Trash',(int)$map->workspace_id,$id);
        wp_send_json_success(['work_area_id'=>$id]);
    }

    public function ajax_permanent_delete_work_area(){
        $this->verify(); global $wpdb; $id=absint($_POST['id']??0); $map=$this->work_area_workspace_map($id);
        if(!$map || !$this->workspace_permission((int)$map->workspace_id,'delete')) wp_send_json_error(['message'=>'Only a Workspace Owner can permanently delete this Work Area'],403);
        $wa=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['work_areas']} WHERE id=%d",$id));
        if(!$wa) wp_send_json_error(['message'=>'Work Area not found'],404);
        if((string)$wa->status!=='trash') wp_send_json_error(['message'=>'Move the Work Area to Trash before deleting it permanently'],422);
        $wid=(int)$map->workspace_id;
        $task_ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->tables['tasks']} WHERE work_area_id=%d",$id)));
        $wpdb->query('START TRANSACTION');
        foreach($task_ids as $tid){
            $sub_ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->tables['checklists']} WHERE task_id=%d",$tid)));
            foreach($sub_ids as $sid) $wpdb->delete($this->tables['entity_assignments'],['entity_type'=>'subtask','entity_id'=>$sid]);
            $wpdb->delete($this->tables['history'],['task_id'=>$tid]);
            $wpdb->delete($this->tables['score_archive'],['task_id'=>$tid]);
            $wpdb->delete($this->tables['recurrence_exceptions'],['task_id'=>$tid]);
            $wpdb->delete($this->tables['subtask_progress'],['task_id'=>$tid]);
            $wpdb->delete($this->tables['checklists'],['task_id'=>$tid]);
            $wpdb->delete($this->tables['task_remarks'],['task_id'=>$tid]);
            $wpdb->delete($this->tables['task_reminders'],['task_id'=>$tid]);$wpdb->delete($this->tables['task_overdue_reminders'],['task_id'=>$tid]);
            $wpdb->delete($this->tables['reminder_delivery'],['task_id'=>$tid]);
            $wpdb->delete($this->tables['attachments'],['task_id'=>$tid]);
            $wpdb->delete($this->tables['notifications'],['task_id'=>$tid]);
            $wpdb->delete($this->tables['entity_assignments'],['entity_type'=>'task','entity_id'=>$tid]);
            $wpdb->delete($this->tables['tasks'],['id'=>$tid]);
        }
        $wpdb->delete($this->tables['entity_assignments'],['entity_type'=>'work_area','entity_id'=>$id]);
        $wpdb->delete($this->tables['workspace_areas'],['work_area_id'=>$id]);
        $wpdb->delete($this->tables['activity'],['work_area_id'=>$id]);
        $wpdb->delete($this->tables['work_areas'],['id'=>$id]);
        $wpdb->query('COMMIT');
        wp_send_json_success(['message'=>'Work Area permanently deleted','workspace_id'=>$wid]);
    }

    public function ajax_move_work_area(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $workspace_id=absint($_POST['workspace_id']??0); $work_area_id=absint($_POST['work_area_id']??0); $direction=sanitize_key($_POST['direction']??'');
        if(!$workspace_id || !$work_area_id || !in_array($direction,['left','right'],true)) wp_send_json_error(['message'=>'Invalid Work Area move request']);
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tables['workspace_areas']} WHERE workspace_id=%d ORDER BY sort_order ASC, work_area_id ASC",$workspace_id));
        if(count($rows)<2) wp_send_json_success();
        $idx=-1; foreach($rows as $i=>$r){ if((int)$r->work_area_id===$work_area_id){$idx=$i;break;} }
        if($idx<0) wp_send_json_error(['message'=>'Work Area is not part of this Workspace']);
        $swap=$direction==='left'?$idx-1:$idx+1; if($swap<0 || $swap>=count($rows)) wp_send_json_success();
        $a=$rows[$idx]; $b=$rows[$swap];
        // workspace_areas has a composite primary key (workspace_id, work_area_id), not an id column.
        // Normalize first, then swap by the composite key so left/right movement persists correctly.
        foreach($rows as $i=>$r){
            $wpdb->update($this->tables['workspace_areas'],['sort_order'=>$i],['workspace_id'=>$workspace_id,'work_area_id'=>(int)$r->work_area_id]);
        }
        $wpdb->update($this->tables['workspace_areas'],['sort_order'=>$swap],['workspace_id'=>$workspace_id,'work_area_id'=>(int)$a->work_area_id]);
        $wpdb->update($this->tables['workspace_areas'],['sort_order'=>$idx],['workspace_id'=>$workspace_id,'work_area_id'=>(int)$b->work_area_id]);
        $this->log_activity('work_area_moved','Work Area moved '.($direction==='left'?'left':'right'),$workspace_id,$work_area_id);
        wp_send_json_success();
    }


    public function ajax_reorder_work_areas(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $workspace_id=absint($_POST['workspace_id']??0);
        $ids_raw=$_POST['work_area_ids']??[];
        if(!is_array($ids_raw)) $ids_raw=explode(',',(string)$ids_raw);
        $ids=array_values(array_unique(array_filter(array_map('absint',$ids_raw))));
        if(!$workspace_id || !$ids) wp_send_json_error(['message'=>'Invalid Work Area order']);
        $valid=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT work_area_id FROM {$this->tables['workspace_areas']} WHERE workspace_id=%d",$workspace_id)));
        foreach($ids as $id){ if(!in_array($id,$valid,true)) wp_send_json_error(['message'=>'Work Area is not part of this Workspace'],422); }
        foreach($ids as $i=>$id) $wpdb->update($this->tables['workspace_areas'],['sort_order'=>$i],['workspace_id'=>$workspace_id,'work_area_id'=>$id]);
        $this->log_activity('work_areas_reordered','Work Area order updated',$workspace_id,$ids[0]??0);
        wp_send_json_success(['order'=>$ids]);
    }

    public function ajax_reorder_tasks(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $ids_raw=$_POST['task_ids']??[];
        if(!is_array($ids_raw)) $ids_raw=explode(',',(string)$ids_raw);
        $ids=array_values(array_unique(array_filter(array_map('absint',$ids_raw))));
        if(!$ids) wp_send_json_error(['message'=>'No tasks supplied']);
        foreach($ids as $i=>$id){
            $wpdb->update($this->tables['tasks'],['sort_order'=>$i],['id'=>$id]);
        }
        $first=$wpdb->get_row($wpdb->prepare("SELECT workspace_id,work_area_id FROM {$this->tables['tasks']} WHERE id=%d",$ids[0]));
        if($first) $this->log_activity('tasks_reordered','Task order updated',(int)$first->workspace_id,(int)$first->work_area_id,(int)$ids[0]);
        wp_send_json_success(['order'=>$ids]);
    }


    public function ajax_move_task_work_area(){
        $this->verify(); global $wpdb;
        $task_id=absint($_POST['task_id']??0);
        $work_area_id=absint($_POST['work_area_id']??0);
        if(!$task_id || !$work_area_id) wp_send_json_error(['message'=>'Invalid task or Work Area']);
        $task=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d",$task_id));
        if(!$task) wp_send_json_error(['message'=>'Task not found']);
        if(!$this->task_permission($task_id,'edit')) wp_send_json_error(['message'=>'Only a Task Owner can move this task'],403);
        $workspace_id=absint($_POST['workspace_id']??0)?:absint($task->workspace_id);
        $workspace_exists=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['workspaces']} WHERE id=%d",$workspace_id));
        if(!$workspace_exists) wp_send_json_error(['message'=>'Destination Workspace not found'],422);
        $valid=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['workspace_areas']} WHERE workspace_id=%d AND work_area_id=%d",$workspace_id,$work_area_id));
        if(!$valid) wp_send_json_error(['message'=>'Destination Work Area is not part of the selected Workspace'],422);
        $from_workspace=(int)$task->workspace_id;
        $from_area=(int)$task->work_area_id;
        if($from_workspace!==$workspace_id || $from_area!==$work_area_id){
            $from_workspace_name=(string)$wpdb->get_var($wpdb->prepare("SELECT name FROM {$this->tables['workspaces']} WHERE id=%d",$from_workspace));
            $to_workspace_name=(string)$wpdb->get_var($wpdb->prepare("SELECT name FROM {$this->tables['workspaces']} WHERE id=%d",$workspace_id));
            $from_name=(string)$wpdb->get_var($wpdb->prepare("SELECT name FROM {$this->tables['work_areas']} WHERE id=%d",$from_area));
            $to_name=(string)$wpdb->get_var($wpdb->prepare("SELECT name FROM {$this->tables['work_areas']} WHERE id=%d",$work_area_id));
            $keep_stage='';
            if(!empty($task->stage_name)){ $valid_stage=$this->work_area_has_stage($work_area_id,(string)$task->stage_name); if($valid_stage) $keep_stage=$valid_stage; }
            $next_sort=1+(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(sort_order),0) FROM {$this->tables['tasks']} WHERE workspace_id=%d AND work_area_id=%d",$workspace_id,$work_area_id));
            $move_data=['workspace_id'=>$workspace_id,'work_area_id'=>$work_area_id,'stage_name'=>$keep_stage,'sort_order'=>$next_sort,'updated_at'=>current_time('mysql')];
            if(!$keep_stage && $task->status==='In Progress') $move_data['status']='Pending';
            $wpdb->update($this->tables['tasks'],$move_data,['id'=>$task_id]);
            $message='Moved from '.$from_workspace_name.' / '.$from_name.' to '.$to_workspace_name.' / '.$to_name;
            $this->log_activity('task_moved_work_area',$message,$workspace_id,$work_area_id,$task_id);
        }
        $ids_raw=$_POST['task_ids']??[];
        if(!is_array($ids_raw)) $ids_raw=explode(',',(string)$ids_raw);
        $ids=array_values(array_unique(array_filter(array_map('absint',$ids_raw))));
        foreach($ids as $i=>$id){
            $belongs=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['tasks']} WHERE id=%d AND workspace_id=%d AND work_area_id=%d",$id,$workspace_id,$work_area_id));
            if($belongs) $wpdb->update($this->tables['tasks'],['sort_order'=>$i],['id'=>$id]);
        }
        wp_send_json_success(['task_id'=>$task_id,'from_workspace_id'=>$from_workspace,'from_work_area_id'=>$from_area,'workspace_id'=>$workspace_id,'work_area_id'=>$work_area_id,'order'=>$ids]);
    }

    private function ensure_department_master($name){
        global $wpdb;
        $name=trim(sanitize_text_field((string)$name));
        if(!$name) return '';
        $row=$wpdb->get_row($wpdb->prepare("SELECT id,name,status FROM {$this->tables['departments']} WHERE LOWER(name)=LOWER(%s) LIMIT 1",$name));
        if($row){
            if((string)$row->status==='inactive') $wpdb->update($this->tables['departments'],['status'=>'active','updated_at'=>current_time('mysql')],['id'=>(int)$row->id]);
            return (string)$row->name;
        }
        $code=$this->next_auto_code($this->tables['departments'],'department_code','DEPT');
        $wpdb->insert($this->tables['departments'],['department_code'=>$code,'name'=>$name,'description'=>'','head_employee_id'=>null,'login_enabled'=>0,'can_complete'=>1,'wp_user_id'=>null,'status'=>'active','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);
        return $name;
    }

    private function ensure_designation_master($name){
        global $wpdb;
        $name=trim(sanitize_text_field((string)$name));
        if(!$name) return '';
        $row=$wpdb->get_row($wpdb->prepare("SELECT id,name,status FROM {$this->tables['designations']} WHERE LOWER(name)=LOWER(%s) LIMIT 1",$name));
        if($row){
            if((string)$row->status==='inactive') $wpdb->update($this->tables['designations'],['status'=>'active','updated_at'=>current_time('mysql')],['id'=>(int)$row->id]);
            return (string)$row->name;
        }
        $wpdb->insert($this->tables['designations'],['name'=>$name,'status'=>'active','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);
        return $name;
    }

    private function sync_people_masters(){
        global $wpdb;
        $rows=$wpdb->get_results("SELECT DISTINCT department,designation FROM {$this->tables['employees']} WHERE (department IS NOT NULL AND department<>'') OR (designation IS NOT NULL AND designation<>'')");
        foreach((array)$rows as $r){
            if(!empty($r->department)) $this->ensure_department_master($r->department);
            if(!empty($r->designation)) $this->ensure_designation_master($r->designation);
        }
    }

    public function ajax_save_employee(){
        $this->verify(); $this->require_manage(); global $wpdb; $id=absint($_POST['id']??0);
        $existing=$id?$wpdb->get_row($wpdb->prepare("SELECT employee_code,wp_user_id,system_role,attendance_enabled,attendance_enabled_from FROM {$this->tables['employees']} WHERE id=%d",$id)):null;
        $login_id=sanitize_user($_POST['employee_code']??'',true);
        $login_password=(string)($_POST['login_password']??'');
        if(!$login_id) wp_send_json_error(['message'=>'Login ID is required']);
        if(!$id && !$login_password) wp_send_json_error(['message'=>'Password is required']);
        $dup=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['employees']} WHERE LOWER(employee_code)=LOWER(%s) AND id<>%d",$login_id,$id));
        if($dup) wp_send_json_error(['message'=>'Login ID already exists in Workboard']);

        // employee_code is the public Workboard Login ID. It is intentionally independent of the
        // hidden WordPress username used behind the scenes. This lets a Workboard Login ID such as
        // "pankaj" be used even when WordPress already has an account with username "pankaj".
        $wp_user_id=$existing?absint($existing->wp_user_id):0;
        if(!$wp_user_id){
            $internal_username=$login_id;
            if(username_exists($internal_username)){
                $base=sanitize_user('bb_'.$login_id,true);
                if(!$base) $base='bb_user';
                $internal_username=$base;
                $suffix=2;
                while(username_exists($internal_username)){
                    $internal_username=$base.'_'.$suffix;
                    $suffix++;
                }
            }
            $employee_email=sanitize_email($_POST['email']??'');
            // WordPress requires account emails to be unique. Workboard itself may still keep the
            // employee email even when that address already belongs to another WordPress account.
            $account_email=($employee_email && !email_exists($employee_email))?$employee_email:'';
            $uid=wp_create_user($internal_username,$login_password,$account_email);
            if(is_wp_error($uid)) wp_send_json_error(['message'=>$uid->get_error_message()]);
            $u=new WP_User($uid); $u->set_role('bb_employee'); $wp_user_id=$uid;
        } elseif($login_password){
            wp_set_password($login_password,$wp_user_id);
        }
        $system_role=sanitize_key($_POST['system_role']??'employee'); if(!in_array($system_role,['owner','manager','employee'],true)) $system_role='employee';
        $attendance_enabled=!empty($_POST['attendance_enabled'])?1:0;
        $attendance_supervisor_employee_id=absint($_POST['attendance_supervisor_employee_id']??0);
        if($id && $attendance_supervisor_employee_id===(int)$id) $attendance_supervisor_employee_id=0;
        $raw_viewers=wp_unslash($_POST['attendance_viewer_employee_ids']??'[]');
        $attendance_viewer_employee_ids=json_decode((string)$raw_viewers,true);
        if(!is_array($attendance_viewer_employee_ids)) $attendance_viewer_employee_ids=[];
        $attendance_viewer_employee_ids=array_values(array_unique(array_filter(array_map('absint',$attendance_viewer_employee_ids))));
        if($id) $attendance_viewer_employee_ids=array_values(array_filter($attendance_viewer_employee_ids,fn($viewer_id)=>(int)$viewer_id!==(int)$id));
        if($attendance_viewer_employee_ids){
            $placeholders=implode(',',array_fill(0,count($attendance_viewer_employee_ids),'%d'));
            $valid_viewers=array_map('intval',$wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$this->tables['employees']} WHERE id IN ($placeholders) AND status<>'inactive'",
                ...$attendance_viewer_employee_ids
            )));
            $attendance_viewer_employee_ids=array_values(array_intersect($attendance_viewer_employee_ids,$valid_viewers));
        }
        // Attendance history belongs to the User, not to the User's current system role.
        // Preserve the original attendance start date across Employee → Manager → Owner → Employee changes.
        $attendance_enabled_from=($existing && !empty($existing->attendance_enabled_from))?(string)$existing->attendance_enabled_from:null;
        if($attendance_enabled && !$attendance_enabled_from) $attendance_enabled_from=current_time('Y-m-d');
        $salary=max(0,(float)($_POST['salary']??0));
        $use_org_work_hours=!empty($_POST['use_org_work_hours'])?1:0;
        $in_time=sanitize_text_field($_POST['in_time']??'');
        $out_time=sanitize_text_field($_POST['out_time']??'');
        if(!$use_org_work_hours && $attendance_enabled && (!$in_time || !$out_time)) wp_send_json_error(['message'=>'Select both In Time and Out Time, or use Organisation Default working hours']);
        $use_org_weekly_off=!empty($_POST['use_org_weekly_off'])?1:0;
        $weekly_off_rule=sanitize_text_field($_POST['weekly_off_rule']??'');
        if(!$use_org_weekly_off && $attendance_enabled && !$weekly_off_rule) wp_send_json_error(['message'=>'Enter a Weekly Off rule, or use Organisation Default']);
        $department=$this->ensure_department_master($_POST['department']??''); $designation=$this->ensure_designation_master($_POST['designation']??'');
        $data=['employee_code'=>$login_id,'name'=>sanitize_text_field($_POST['name']??''),'email'=>sanitize_email($_POST['email']??''),'mobile'=>preg_replace('/[^0-9+]/','',$_POST['mobile']??''),'work_area_id'=>absint($_POST['work_area_id']??0)?:null,'designation'=>$designation,'department'=>$department,'address'=>sanitize_textarea_field($_POST['address']??''),'system_role'=>$system_role,'status'=>sanitize_key($_POST['status']??'active'),'joined_on'=>sanitize_text_field($_POST['joined_on']??'')?:null,'attendance_enabled'=>$attendance_enabled,'attendance_enabled_from'=>$attendance_enabled_from,'attendance_supervisor'=>0,'attendance_supervisor_employee_ids'=>null,'attendance_supervisor_employee_id'=>$attendance_supervisor_employee_id?:null,'attendance_view_others'=>0,'attendance_view_employee_id'=>null,'attendance_viewer_employee_ids'=>wp_json_encode($attendance_viewer_employee_ids),'salary'=>number_format($salary,2,'.',''),'use_org_work_hours'=>$use_org_work_hours,'in_time'=>$use_org_work_hours?null:($in_time?:null),'out_time'=>$use_org_work_hours?null:($out_time?:null),'use_org_weekly_off'=>$use_org_weekly_off,'weekly_off_rule'=>$use_org_weekly_off?'':$weekly_off_rule,'wp_user_id'=>$wp_user_id];
        if(!$data['name']) wp_send_json_error(['message'=>'User name is required']);
        $ok=$id?$wpdb->update($this->tables['employees'],$data,['id'=>$id]):$wpdb->insert($this->tables['employees'],$data);
        if($ok===false){
            // If this was a new employee and the Workboard row could not be stored, remove the
            // internal account we just created so there is no orphan login.
            if(!$id && $wp_user_id){ require_once ABSPATH.'wp-admin/includes/user.php'; wp_delete_user($wp_user_id); }
            wp_send_json_error(['message'=>'Could not save user. Please try again.']);
        }
        $eid=$id?:$wpdb->insert_id;
        $this->log_activity($id?'employee_updated':'employee_created',$data['name']);
        wp_send_json_success(['employee_id'=>$eid,'message'=>$id?'User updated':'User added']);
    }
    public function ajax_delete_employee(){ $this->verify(); $this->require_manage(); global $wpdb; $id=absint($_POST['id']??0); $wpdb->delete($this->tables['employee_departments'],['employee_id'=>$id]); $wpdb->delete($this->tables['employees'],['id'=>$id]); wp_send_json_success(); }

    // v4.13.87: AttendSpace always presents attendance in Indian Standard Time (Asia/Kolkata),
    // independent of the WordPress/server timezone. Existing stored timestamps remain untouched.
    private function attendance_ist_timezone(){ return new DateTimeZone('Asia/Kolkata'); }
    private function attendance_ist_now($format='Y-m-d H:i:s'){
        return (new DateTimeImmutable('now',$this->attendance_ist_timezone()))->format($format);
    }
    private function attendance_storage_to_ist($mysql){
        $mysql=trim((string)$mysql); if(!$mysql) return '';
        try{
            $dt=new DateTimeImmutable($mysql,wp_timezone());
            return $dt->setTimezone($this->attendance_ist_timezone())->format('Y-m-d H:i:s');
        }catch(Exception $e){ return $mysql; }
    }
    private function attendance_ist_to_storage($date,$time){
        try{
            $dt=new DateTimeImmutable(trim((string)$date).' '.trim((string)$time).':00',$this->attendance_ist_timezone());
            return $dt->setTimezone(wp_timezone())->format('Y-m-d H:i:s');
        }catch(Exception $e){ return trim((string)$date).' '.trim((string)$time).':00'; }
    }
    private function attendance_add_ist_display(&$rows){
        foreach((array)$rows as $row){
            if(is_object($row) && !empty($row->punched_at)) $row->punched_at_ist=$this->attendance_storage_to_ist($row->punched_at);
            if(is_object($row) && !empty($row->old_punched_at)) $row->old_punched_at_ist=$this->attendance_storage_to_ist($row->old_punched_at);
            if(is_object($row) && !empty($row->new_punched_at)) $row->new_punched_at_ist=$this->attendance_storage_to_ist($row->new_punched_at);
        }
    }


    // v4.13.98: Convert captured GPS coordinates into a human-readable address.
    // The raw latitude/longitude remain the source of truth; the address is stored only as a display aid.
    private function attendance_reverse_geocode($latitude,$longitude){
        if($latitude===null || $longitude===null || !is_numeric($latitude) || !is_numeric($longitude)) return '';
        $lat=(float)$latitude; $lng=(float)$longitude;
        if($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return '';
        $cache_key='bb_att_addr_'.md5(number_format($lat,5,'.','').','.number_format($lng,5,'.',''));
        $cached=get_transient($cache_key);
        if($cached!==false) return (string)$cached;
        $url=add_query_arg([
            'format'=>'jsonv2',
            'lat'=>number_format($lat,7,'.',''),
            'lon'=>number_format($lng,7,'.',''),
            'zoom'=>18,
            'addressdetails'=>1,
            'accept-language'=>'en'
        ],'https://nominatim.openstreetmap.org/reverse');
        $response=wp_remote_get($url,[
            'timeout'=>5,
            'redirection'=>2,
            'headers'=>[
                'User-Agent'=>'Workboard/'.self::VERSION.' ('.home_url('/').')',
                'Referer'=>home_url('/'),
                'Accept-Language'=>'en'
            ]
        ]);
        if(is_wp_error($response) || (int)wp_remote_retrieve_response_code($response)!==200) return '';
        $json=json_decode((string)wp_remote_retrieve_body($response),true);
        $address=sanitize_text_field((string)($json['display_name']??''));
        if(!$address) return '';
        if(function_exists('mb_strlen') && mb_strlen($address)>600) $address=mb_substr($address,0,600);
        elseif(strlen($address)>600) $address=substr($address,0,600);
        set_transient($cache_key,$address,30*DAY_IN_SECONDS);
        return $address;
    }


    // v4.14.25: Resolve readable punch addresses asynchronously so the IN/OUT punch itself returns fast.
    public function attendance_reverse_geocode_event($event_id){
        global $wpdb;
        $event_id=absint($event_id); if(!$event_id) return;
        $ev=$wpdb->get_row($wpdb->prepare("SELECT id,latitude,longitude,location_address FROM {$this->tables['attendance_events']} WHERE id=%d LIMIT 1",$event_id));
        if(!$ev || trim((string)$ev->location_address)!=='') return;
        if($ev->latitude===null || $ev->longitude===null || !is_numeric($ev->latitude) || !is_numeric($ev->longitude)) return;
        $address=$this->attendance_reverse_geocode((float)$ev->latitude,(float)$ev->longitude);
        if($address!=='') $wpdb->update($this->tables['attendance_events'],['location_address'=>$address],['id'=>$event_id]);
    }

    private function ensure_attendance_live_punch_schema(){
        global $wpdb;
        $employees=$this->tables['employees'];
        $events=$this->tables['attendance_events'];

        $has_supervisor=$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$employees} LIKE %s",'attendance_supervisor_employee_id'));
        if(!$has_supervisor){
            $wpdb->query("ALTER TABLE {$employees} ADD COLUMN attendance_supervisor_employee_id bigint(20) unsigned DEFAULT NULL AFTER attendance_supervisor_employee_ids");
        }
        $has_view_others=$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$employees} LIKE %s",'attendance_view_others'));
        if(!$has_view_others){
            $wpdb->query("ALTER TABLE {$employees} ADD COLUMN attendance_view_others tinyint(1) DEFAULT 0 AFTER attendance_supervisor_employee_id");
        }
        $has_view_employee=$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$employees} LIKE %s",'attendance_view_employee_id'));
        if(!$has_view_employee){
            $wpdb->query("ALTER TABLE {$employees} ADD COLUMN attendance_view_employee_id bigint(20) unsigned DEFAULT NULL AFTER attendance_view_others");
        }
        $has_viewers=$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$employees} LIKE %s",'attendance_viewer_employee_ids'));
        if(!$has_viewers){
            $wpdb->query("ALTER TABLE {$employees} ADD COLUMN attendance_viewer_employee_ids longtext DEFAULT NULL AFTER attendance_view_employee_id");
            // Migrate old forward permission (viewer -> target) into new inverse permission (target -> viewers).
            $legacy_rows=$wpdb->get_results("SELECT id,attendance_view_others,attendance_view_employee_id FROM {$employees} WHERE attendance_view_others=1 AND attendance_view_employee_id IS NOT NULL");
            $by_target=[];
            foreach($legacy_rows as $legacy){
                $target=absint($legacy->attendance_view_employee_id??0); $viewer=absint($legacy->id??0);
                if($target && $viewer && $target!==$viewer) $by_target[$target][]=$viewer;
            }
            foreach($by_target as $target=>$viewers){
                $viewers=array_values(array_unique(array_map('intval',$viewers)));
                $wpdb->update($employees,['attendance_viewer_employee_ids'=>wp_json_encode($viewers)],['id'=>(int)$target]);
            }
        }

        $required=[
            'created_by_employee_id'=>"ALTER TABLE {$events} ADD COLUMN created_by_employee_id bigint(20) unsigned DEFAULT NULL AFTER punched_at",
            'created_by_wp_user_id'=>"ALTER TABLE {$events} ADD COLUMN created_by_wp_user_id bigint(20) unsigned DEFAULT NULL AFTER created_by_employee_id",
            'source'=>"ALTER TABLE {$events} ADD COLUMN source varchar(30) DEFAULT 'web' AFTER created_by_wp_user_id",
            'latitude'=>"ALTER TABLE {$events} ADD COLUMN latitude decimal(10,7) DEFAULT NULL AFTER source",
            'longitude'=>"ALTER TABLE {$events} ADD COLUMN longitude decimal(10,7) DEFAULT NULL AFTER latitude",
            'location_accuracy'=>"ALTER TABLE {$events} ADD COLUMN location_accuracy decimal(10,2) DEFAULT NULL AFTER longitude",
            'location_address'=>"ALTER TABLE {$events} ADD COLUMN location_address text DEFAULT NULL AFTER location_accuracy",
        ];
        foreach($required as $column=>$sql){
            $exists=$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$events} LIKE %s",$column));
            if(!$exists) $wpdb->query($sql);
        }
    }

    private function attendance_employee($employee_id){
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['employees']} WHERE id=%d LIMIT 1",absint($employee_id)));
    }
    private function attendance_employee_is_enabled($employee){
        if(!$employee || (string)$employee->status==='inactive' || empty($employee->attendance_enabled)) return false;
        // Attendance is independent of Owner / Manager / Employee role.
        return true;
    }
    private function attendance_can_punch_employee($employee_id){
        if($this->is_privileged_user()) return true;
        $me=$this->current_employee();
        if(!$me || (string)$me->status==='inactive') return false;
        if((int)$me->id===(int)$employee_id) return true;

        $target=$this->attendance_employee($employee_id);
        if(!$target || !$this->attendance_employee_is_enabled($target)) return false;

        $supervisor_id=absint($target->attendance_supervisor_employee_id??0);

        // Backward compatibility for v4.15.28/29 data.
        if(!$supervisor_id && !empty($target->attendance_supervisor_employee_ids)){
            $legacy=json_decode((string)$target->attendance_supervisor_employee_ids,true);
            if(is_array($legacy) && !empty($legacy)) $supervisor_id=absint(reset($legacy));
        }

        return $supervisor_id>0 && $supervisor_id===(int)$me->id;
    }
    private function attendance_day_events($employee_id,$date){
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tables['attendance_events']} WHERE employee_id=%d AND attendance_date=%s ORDER BY punched_at ASC,id ASC",absint($employee_id),sanitize_text_field($date)));
    }
    private function attendance_recorded_overtime_minutes($employee,$date){
        global $wpdb;
        $events=$this->attendance_day_events((int)$employee->id,$date); $main=[]; $combos=[];
        foreach($events as $ev){ if((string)$ev->event_type==='main') $main[]=$ev; elseif((string)$ev->event_type==='combo' && !empty($ev->combo_id)) $combos[(int)$ev->combo_id][]=$ev; }
        $last_main=$main?end($main):null;
        if(!$last_main || strtolower((string)$last_main->direction)!=='out') return 0;
        $stamp=fn($v)=>(int)strtotime((string)$v); $gross=0; $open=null;
        foreach($main as $ev){ $t=$stamp($ev->punched_at); if(strtolower((string)$ev->direction)==='in'){ if($open===null)$open=$t; } elseif($open!==null){ $gross+=max(0,(int)round(($t-$open)/60)); $open=null; } }
        $recorded_break=0;
        foreach($combos as $combo_id=>$list){ $combo=$wpdb->get_row($wpdb->prepare("SELECT counts_as_working_time FROM {$this->tables['attendance_combos']} WHERE id=%d",$combo_id)); if($combo && (int)$combo->counts_as_working_time===1) continue; $out=null; foreach($list as $ev){ $t=$stamp($ev->punched_at); if(strtolower((string)$ev->direction)==='out'){ if($out===null)$out=$t; } elseif($out!==null){ $recorded_break+=max(0,(int)round(($t-$out)/60)); $out=null; } } if($out!==null)return 0; }
        $standard_break=(int)get_option('bb_attendance_standard_break_minutes',45); $auto_after=(int)get_option('bb_attendance_auto_break_after_minutes',360); $break_minutes=$recorded_break?:($gross>=$auto_after?$standard_break:0); $net=max(0,$gross-$break_minutes);
        $use_org=(int)($employee->use_org_work_hours??1)!==0; $in=$use_org?(string)get_option('bb_attendance_default_in_time','09:00'):(string)$employee->in_time; $out=$use_org?(string)get_option('bb_attendance_default_out_time','18:00'):(string)$employee->out_time;
        [$ih,$im]=array_map('intval',explode(':',$in)); [$oh,$om]=array_map('intval',explode(':',$out)); $start=$ih*60+$im; $end=$oh*60+$om; $span=$end>=$start?$end-$start:(1440-$start)+$end; $standard=max(1,$span-$standard_break);
        return max(0,$net-$standard);
    }
    private function attendance_last_direction($events,$type='main',$combo_id=0){
        $last='';
        foreach((array)$events as $ev){
            if((string)$ev->event_type!==$type) continue;
            if($type==='combo' && (int)$ev->combo_id!==(int)$combo_id) continue;
            $last=strtolower((string)$ev->direction);
        }
        return $last;
    }
    private function attendance_open_combo($events){
        $states=[];
        foreach((array)$events as $ev){ if((string)$ev->event_type==='combo' && $ev->combo_id) $states[(int)$ev->combo_id]=strtolower((string)$ev->direction); }
        foreach($states as $cid=>$dir) if($dir==='out') return (int)$cid;
        return 0;
    }
    public function ajax_save_attendance_combo(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $id=absint($_POST['id']??0);
        $name=trim(sanitize_text_field($_POST['name']??''));
        if(!$name) wp_send_json_error(['message'=>'Combo Name is required'],422);
        $out=trim(sanitize_text_field($_POST['out_label']??'')); if(!$out) $out=$name.' Out';
        $in=trim(sanitize_text_field($_POST['in_label']??'')); if(!$in) $in=$name.' In';
        $status=sanitize_key($_POST['status']??'active'); if(!in_array($status,['active','inactive'],true)) $status='active';
        $data=['name'=>$name,'out_label'=>$out,'in_label'=>$in,'status'=>$status,'counts_as_working_time'=>!empty($_POST['counts_as_working_time'])?1:0,'updated_at'=>current_time('mysql')];
        if(!$id){ $max=(int)$wpdb->get_var("SELECT MAX(sort_order) FROM {$this->tables['attendance_combos']}"); $data['sort_order']=$max+1; $data['created_at']=current_time('mysql'); }
        $ok=$id?$wpdb->update($this->tables['attendance_combos'],$data,['id'=>$id]):$wpdb->insert($this->tables['attendance_combos'],$data);
        if($ok===false) wp_send_json_error(['message'=>'Could not save attendance combo']);
        wp_send_json_success(['combo_id'=>$id?:$wpdb->insert_id]);
    }
    public function ajax_delete_attendance_combo(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $id=absint($_POST['id']??0); if(!$id) wp_send_json_error(['message'=>'Combo not found'],404);
        $used=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['attendance_events']} WHERE combo_id=%d",$id));
        if($used) $wpdb->update($this->tables['attendance_combos'],['status'=>'inactive','updated_at'=>current_time('mysql')],['id'=>$id]);
        else $wpdb->delete($this->tables['attendance_combos'],['id'=>$id]);
        wp_send_json_success(['archived'=>$used?1:0]);
    }
    public function ajax_save_attendance_holiday(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $date=sanitize_text_field($_POST['holiday_date']??'');
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) wp_send_json_error(['message'=>'Select a valid holiday date'],422);
        $name=trim(sanitize_text_field($_POST['holiday_name']??''));
        $actor=$this->current_employee();
        $existing=$wpdb->get_row($wpdb->prepare("SELECT id FROM {$this->tables['attendance_holidays']} WHERE holiday_date=%s LIMIT 1",$date));
        $data=['holiday_date'=>$date,'holiday_name'=>$name,'updated_at'=>current_time('mysql')];
        if($existing){
            $ok=$wpdb->update($this->tables['attendance_holidays'],$data,['id'=>(int)$existing->id]); $id=(int)$existing->id;
        }else{
            $data['created_by_employee_id']=$actor?(int)$actor->id:null; $data['created_by_wp_user_id']=get_current_user_id()?:null; $data['created_at']=current_time('mysql');
            $ok=$wpdb->insert($this->tables['attendance_holidays'],$data); $id=(int)$wpdb->insert_id;
        }
        if($ok===false) wp_send_json_error(['message'=>'Could not mark this date as Holiday']);
        $this->log_activity('attendance_holiday_saved',($name?:'Holiday').' · '.$date);
        wp_send_json_success(['id'=>$id,'holiday_date'=>$date,'holiday_name'=>$name]);
    }

    public function ajax_delete_attendance_holiday(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $date=sanitize_text_field($_POST['holiday_date']??''); $id=absint($_POST['id']??0);
        if($id) $wpdb->delete($this->tables['attendance_holidays'],['id'=>$id]);
        elseif($date) $wpdb->delete($this->tables['attendance_holidays'],['holiday_date'=>$date]);
        wp_send_json_success();
    }

    public function ajax_bulk_save_attendance_holidays(){
        $this->verify(); $this->require_manage(); global $wpdb;

        $raw=wp_unslash($_POST['rows']??'[]');
        $rows=json_decode($raw,true);
        if(!is_array($rows)) wp_send_json_error(['message'=>'Invalid Holiday rows'],422);
        if(count($rows)>500) wp_send_json_error(['message'=>'Maximum 500 Holiday rows can be updated at one time'],422);

        $table=$this->tables['attendance_holidays'];
        $actor=$this->current_employee();
        $now=current_time('mysql');
        $seen=[];
        $saved=0; $deleted=0;

        $wpdb->query('START TRANSACTION');
        try{
            foreach($rows as $index=>$row){
                if(!is_array($row)) continue;
                $id=absint($row['id']??0);
                $date=trim(sanitize_text_field($row['holiday_date']??''));
                $name=trim(sanitize_text_field($row['holiday_name']??''));

                // Existing row with Date cleared = remove it. New blank rows are ignored.
                if($date===''){
                    if($id){
                        if($wpdb->delete($table,['id'=>$id])===false) throw new \RuntimeException('Could not remove Holiday row '.($index+1));
                        $deleted++;
                    }
                    continue;
                }

                $d=\DateTime::createFromFormat('Y-m-d',$date);
                if(!$d || $d->format('Y-m-d')!==$date){
                    throw new \RuntimeException('Row '.($index+1).': select a valid Date.');
                }

                if(isset($seen[$date])){
                    throw new \RuntimeException('Row '.($index+1).': '.$date.' is repeated in the Holiday table.');
                }
                $seen[$date]=true;

                if($name==='') $name='Holiday';

                $conflict=$wpdb->get_row($wpdb->prepare(
                    "SELECT id FROM {$table} WHERE holiday_date=%s LIMIT 1",
                    $date
                ));

                if($id){
                    if($conflict && (int)$conflict->id!==$id){
                        throw new \RuntimeException('Row '.($index+1).': this Date already exists in Holiday.');
                    }
                    $exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE id=%d",$id));
                    if($exists){
                        $ok=$wpdb->update($table,[
                            'holiday_date'=>$date,
                            'holiday_name'=>$name,
                            'updated_at'=>$now
                        ],['id'=>$id]);
                    }else{
                        $ok=$wpdb->insert($table,[
                            'holiday_date'=>$date,
                            'holiday_name'=>$name,
                            'created_by_employee_id'=>$actor?(int)$actor->id:null,
                            'created_by_wp_user_id'=>get_current_user_id()?:null,
                            'created_at'=>$now,
                            'updated_at'=>$now
                        ]);
                    }
                }elseif($conflict){
                    $ok=$wpdb->update($table,[
                        'holiday_name'=>$name,
                        'updated_at'=>$now
                    ],['id'=>(int)$conflict->id]);
                }else{
                    $ok=$wpdb->insert($table,[
                        'holiday_date'=>$date,
                        'holiday_name'=>$name,
                        'created_by_employee_id'=>$actor?(int)$actor->id:null,
                        'created_by_wp_user_id'=>get_current_user_id()?:null,
                        'created_at'=>$now,
                        'updated_at'=>$now
                    ]);
                }

                if($ok===false) throw new \RuntimeException('Could not update Holiday row '.($index+1));
                $saved++;
            }

            $wpdb->query('COMMIT');
        }catch(\Throwable $e){
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message'=>$e->getMessage()],422);
        }

        $holidays=$wpdb->get_results("SELECT * FROM {$table} ORDER BY holiday_date ASC,id ASC");
        $this->log_activity('attendance_holidays_bulk_updated','Holiday Master bulk updated · '.$saved.' saved · '.$deleted.' removed');
        wp_send_json_success([
            'holidays'=>$holidays,
            'saved'=>$saved,
            'deleted'=>$deleted
        ]);
    }

    public function ajax_claim_weekly_off(){
        $this->verify(); global $wpdb;
        $me=$this->current_employee();
        if(!$me) wp_send_json_error(['message'=>'Employee profile not found'],403);
        if(!$this->attendance_employee_is_enabled($me)) wp_send_json_error(['message'=>'Attendance is not active for your User'],422);
        $date=sanitize_text_field($_POST['claim_date']??'');
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) wp_send_json_error(['message'=>'Select a valid date'],422);
        $today=$this->attendance_ist_now('Y-m-d');
        if($date>$today) wp_send_json_error(['message'=>'Weekly Off cannot be claimed for a future date'],422);
        $from=(string)($me->joined_on??'');
        if($from && $date<$from) wp_send_json_error(['message'=>'This date is before your joining date'],422);
        $holiday=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['attendance_holidays']} WHERE holiday_date=%s",$date));
        if($holiday) wp_send_json_error(['message'=>'This date is already marked as Holiday'],422);
        $has_attendance=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['attendance_events']} WHERE employee_id=%d AND attendance_date=%s AND event_type='main' AND direction='in'",(int)$me->id,$date));
        if($has_attendance) wp_send_json_error(['message'=>'Weekly Off cannot be claimed after attendance has been marked for this date'],422);
        $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['attendance_weekly_off_claims']} WHERE employee_id=%d AND claim_date=%s LIMIT 1",(int)$me->id,$date));
        if($existing && (string)$existing->status==='approved') wp_send_json_error(['message'=>'Weekly Off is already approved for this date'],409);
        if($existing && (string)$existing->status==='pending') wp_send_json_error(['message'=>'Weekly Off claim is already pending approval'],409);
        $reason=trim(sanitize_text_field($_POST['reason']??''));
        $data=['employee_id'=>(int)$me->id,'claim_date'=>$date,'status'=>'pending','reason'=>$reason,'review_note'=>'','submitted_at'=>current_time('mysql'),'reviewed_by_employee_id'=>null,'reviewed_by_wp_user_id'=>null,'reviewed_at'=>null,'updated_at'=>current_time('mysql')];
        if($existing){
            $ok=$wpdb->update($this->tables['attendance_weekly_off_claims'],$data,['id'=>(int)$existing->id]);
            $id=(int)$existing->id;
        }else{
            $ok=$wpdb->insert($this->tables['attendance_weekly_off_claims'],$data);
            $id=(int)$wpdb->insert_id;
        }
        if($ok===false) wp_send_json_error(['message'=>'Could not submit Weekly Off claim']);
        $this->log_activity('weekly_off_claimed',$me->name.' · '.$date);
        wp_send_json_success(['claim'=>array_merge($data,['id'=>$id])]);
    }

    public function ajax_review_weekly_off_claim(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $id=absint($_POST['id']??0);
        $decision=sanitize_key($_POST['decision']??'');
        if(!$id || !in_array($decision,['approved','rejected'],true)) wp_send_json_error(['message'=>'Invalid Weekly Off review'],422);
        $claim=$wpdb->get_row($wpdb->prepare("SELECT c.*,e.name employee_name FROM {$this->tables['attendance_weekly_off_claims']} c LEFT JOIN {$this->tables['employees']} e ON e.id=c.employee_id WHERE c.id=%d LIMIT 1",$id));
        if(!$claim) wp_send_json_error(['message'=>'Weekly Off claim not found'],404);
        $actor=$this->current_employee();
        $note=trim(sanitize_text_field($_POST['review_note']??''));
        $data=['status'=>$decision,'review_note'=>$note,'reviewed_by_employee_id'=>$actor?(int)$actor->id:null,'reviewed_by_wp_user_id'=>get_current_user_id()?:null,'reviewed_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')];
        if($wpdb->update($this->tables['attendance_weekly_off_claims'],$data,['id'=>$id])===false) wp_send_json_error(['message'=>'Could not review Weekly Off claim']);
        $this->log_activity('weekly_off_'.$decision,($claim->employee_name?:'User').' · '.$claim->claim_date);
        wp_send_json_success(['id'=>$id,'status'=>$decision]);
    }

    public function ajax_save_leave_request(){
        $this->verify(); global $wpdb;
        $me=$this->current_employee();
        // WordPress Administrators are intentionally not returned by current_employee().
        // Allow a privileged Administrator to submit on behalf of a real attendance-enabled User.
        if(!$me && $this->is_privileged_user()){
            $posted_employee_id=absint($_POST['employee_id']??0);
            if($posted_employee_id){
                $me=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['employees']} WHERE id=%d AND status<>'inactive' AND attendance_enabled=1 LIMIT 1",$posted_employee_id));
            }
            if(!$me) wp_send_json_error(['message'=>'Select an attendance-enabled User'],422);
        }
        if(!$me) wp_send_json_error(['message'=>'Employee login required'],403);
        $from=sanitize_text_field($_POST['from_date']??'');
        $to=sanitize_text_field($_POST['to_date']??'');
        $reason=trim(sanitize_textarea_field(wp_unslash($_POST['reason']??'')));
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)) wp_send_json_error(['message'=>'Select valid From and To dates'],422);
        if($to<$from) wp_send_json_error(['message'=>'To Date cannot be before From Date'],422);
        if($reason==='') wp_send_json_error(['message'=>'Reason is required'],422);
        $overlap=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['attendance_leaves']} WHERE employee_id=%d AND status IN ('pending','approved') AND from_date<=%s AND to_date>=%s",(int)$me->id,$to,$from));
        if($overlap) wp_send_json_error(['message'=>'A Pending or Approved leave already overlaps these dates'],409);
        $ok=$wpdb->insert($this->tables['attendance_leaves'],['employee_id'=>(int)$me->id,'from_date'=>$from,'to_date'=>$to,'reason'=>$reason,'status'=>'pending','requested_at'=>current_time('mysql')]);
        if($ok===false) wp_send_json_error(['message'=>'Could not submit leave request']);
        $this->log_activity('leave_requested',($me->name?:'User').' · '.$from.' to '.$to);
        wp_send_json_success(['id'=>(int)$wpdb->insert_id,'message'=>'Leave request submitted']);
    }

    public function ajax_review_leave_request(){
        $this->verify(); global $wpdb;
        $id=absint($_POST['id']??0); $status=sanitize_key($_POST['status']??'');
        $remark=trim(sanitize_textarea_field(wp_unslash($_POST['remark']??'')));
        if(!in_array($status,['approved','rejected'],true)) wp_send_json_error(['message'=>'Select Approved or Rejected'],422);
        if($status==='rejected' && $remark==='') wp_send_json_error(['message'=>'Remark is required when rejecting leave'],422);
        $leave=$wpdb->get_row($wpdb->prepare("SELECT l.*,e.name employee_name,e.attendance_supervisor_employee_id FROM {$this->tables['attendance_leaves']} l LEFT JOIN {$this->tables['employees']} e ON e.id=l.employee_id WHERE l.id=%d LIMIT 1",$id));
        if(!$leave) wp_send_json_error(['message'=>'Leave request not found'],404);
        $actor=$this->current_employee(); $allowed=$this->is_privileged_user();
        if(!$allowed && $actor && (int)$leave->attendance_supervisor_employee_id===(int)$actor->id) $allowed=true;
        if(!$allowed) wp_send_json_error(['message'=>'You do not have permission to review this leave'],403);
        if((int)$leave->employee_id===($actor?(int)$actor->id:0) && !$this->is_privileged_user()) wp_send_json_error(['message'=>'You cannot approve your own leave'],403);
        $data=['status'=>$status,'approval_remark'=>$remark,'approver_employee_id'=>$actor?(int)$actor->id:null,'decided_at'=>current_time('mysql')];
        if($wpdb->update($this->tables['attendance_leaves'],$data,['id'=>$id])===false) wp_send_json_error(['message'=>'Could not update leave request']);
        $this->log_activity('leave_'.$status,($leave->employee_name?:'User').' · '.$leave->from_date.' to '.$leave->to_date);
        wp_send_json_success(['message'=>'Leave '.$status]);
    }

    public function ajax_delete_leave_request(){
        $this->verify(); global $wpdb;
        $id=absint($_POST['id']??0);
        if(!$id) wp_send_json_error(['message'=>'Invalid leave request'],422);

        // Leave deletion is intentionally restricted to WordPress Administrators and WorkBoard Managers.
        $allowed=current_user_can('manage_options');
        $actor=$this->current_employee();
        if(!$allowed && $actor && strtolower((string)($actor->system_role??'employee'))==='manager') $allowed=true;
        if(!$allowed) wp_send_json_error(['message'=>'Only Manager or Administrator can delete leave'],403);

        $leave=$wpdb->get_row($wpdb->prepare("SELECT l.*,e.name employee_name FROM {$this->tables['attendance_leaves']} l LEFT JOIN {$this->tables['employees']} e ON e.id=l.employee_id WHERE l.id=%d LIMIT 1",$id));
        if(!$leave) wp_send_json_error(['message'=>'Leave request not found'],404);
        if($wpdb->delete($this->tables['attendance_leaves'],['id'=>$id],['%d'])===false) wp_send_json_error(['message'=>'Could not delete leave']);
        $this->log_activity('leave_deleted',($leave->employee_name?:'User').' · '.$leave->from_date.' to '.$leave->to_date);
        wp_send_json_success(['id'=>$id,'message'=>'Leave deleted']);
    }

    private function attendance_summary_snapshot($period='morning'){
        global $wpdb;
        $date=$this->attendance_ist_now('Y-m-d');
        $employees=$wpdb->get_results("SELECT * FROM {$this->tables['employees']} WHERE status<>'inactive' AND attendance_enabled=1 ORDER BY name ASC");
        $events=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tables['attendance_events']} WHERE attendance_date=%s AND event_type='main' ORDER BY employee_id,punched_at,id",$date));
        $by=[]; foreach((array)$events as $ev) $by[(int)$ev->employee_id][]=$ev;
        $grace=max(0,(int)get_option('bb_attendance_grace_minutes',15));
        $standard_break=max(0,(int)get_option('bb_attendance_standard_break_minutes',45));
        $summary=['date'=>$date,'total'=>0,'present'=>0,'absent'=>0,'late'=>0,'early'=>0,'less'=>0,'leave'=>0,'absent_names'=>[],'late_names'=>[],'early_names'=>[],'less_names'=>[],'leave_names'=>[]];
        foreach((array)$employees as $emp){
            // Attendance applies only after the employee's applicable start date.
            $enabled_from=(string)($emp->attendance_enabled_from??'');
            $joined=(string)($emp->joined_on??'');
            if(($enabled_from && $date<$enabled_from) || ($joined && $date<$joined)) continue;
            $summary['total']++;

            $on_leave=(int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->tables['attendance_leaves']} WHERE employee_id=%d AND status='approved' AND from_date<=%s AND to_date>=%s",
                (int)$emp->id,$date,$date
            ));
            if($on_leave){
                $summary['leave']++;
                $summary['leave_names'][]=(string)$emp->name;
                continue;
            }

            $list=$by[(int)$emp->id]??[]; $first=null; $last=null; $gross=0; $open=null;
            $ist=new DateTimeZone('Asia/Kolkata');
            $to_ts=function($value) use ($ist){
                $value=trim((string)$value);
                if($value==='') return false;
                $dt=DateTime::createFromFormat('Y-m-d H:i:s',$value,$ist);
                if(!$dt) $dt=DateTime::createFromFormat('Y-m-d H:i',$value,$ist);
                return $dt?$dt->getTimestamp():strtotime($value);
            };
            foreach($list as $ev){
                $dir=strtolower(trim((string)$ev->direction)); $t=$to_ts($ev->punched_at);
                if($t===false) continue;
                if($dir==='in'){
                    if($first===null)$first=$t;
                    if($open===null)$open=$t;
                }elseif($dir==='out' && $open!==null){
                    $gross+=max(0,(int)round(($t-$open)/60)); $open=null; $last=$t;
                }
            }
            if($first===null){$summary['absent']++;$summary['absent_names'][]=(string)$emp->name;continue;}
            $summary['present']++;

            $use_org=(int)($emp->use_org_work_hours??1)!==0;
            $org_in=(string)get_option('bb_attendance_default_in_time','09:00');
            $org_out=(string)get_option('bb_attendance_default_out_time','18:00');
            $in_time=$use_org?$org_in:(string)$emp->in_time;
            $out_time=$use_org?$org_out:(string)$emp->out_time;
            if(!$in_time)$in_time=$org_in;
            if(!$out_time)$out_time=$org_out;
            // Normalize any legacy HH:MM:SS values before building the effective schedule.
            $in_time=substr(trim($in_time),0,5);
            $out_time=substr(trim($out_time),0,5);
            $in_dt=DateTime::createFromFormat('Y-m-d H:i',$date.' '.$in_time,$ist);
            $out_dt=DateTime::createFromFormat('Y-m-d H:i',$date.' '.$out_time,$ist);
            $sched_in=$in_dt?$in_dt->getTimestamp():false;
            $sched_out=$out_dt?$out_dt->getTimestamp():false;
            if($sched_out!==false && $sched_in!==false && $sched_out<$sched_in) $sched_out+=DAY_IN_SECONDS;

            // Morning: Late only after employee Start Time + Grace Minutes.
            // Compare clock minutes directly from the stored attendance value. This avoids
            // timezone/server parsing differences while matching the time shown in AttendSpace.
            $first_clock='';
            foreach($list as $ev){
                if(strtolower(trim((string)$ev->direction))==='in'){
                    // Attendance timestamps are stored in the WordPress timezone, while AttendSpace
                    // displays them in IST. Always compare the same IST clock time the user sees.
                    $pv=$this->attendance_storage_to_ist((string)$ev->punched_at);
                    if(preg_match('/(?:^|\s)([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/',$pv,$pm)){
                        $first_clock=$pm[1].':'.$pm[2];
                        break;
                    }
                }
            }
            $schedule_parts=explode(':',$in_time);
            $first_parts=$first_clock!==''?explode(':',$first_clock):[];
            if(count($schedule_parts)>=2 && count($first_parts)>=2){
                $schedule_min=((int)$schedule_parts[0]*60)+(int)$schedule_parts[1];
                $first_min=((int)$first_parts[0]*60)+(int)$first_parts[1];
                $late_threshold=$schedule_min+$grace;
                if($first_min>$late_threshold){
                    $late_minutes=$first_min-$late_threshold;
                    $display_dt=DateTime::createFromFormat('H:i',$first_clock,$ist);
                    $display_time=$display_dt?$display_dt->format('g:i A'):$first_clock;
                    $summary['late']++;
                    $summary['late_names'][]=(string)$emp->name.' — '.$display_time.' — Late '.$late_minutes.'m';
                }
            }elseif($sched_in && $first>$sched_in+($grace*60)){
                // Fallback for non-standard legacy attendance timestamps.
                $late_minutes=max(1,(int)floor(($first-($sched_in+$grace*60))/60));
                $summary['late']++;
                $summary['late_names'][]=(string)$emp->name.' — '.date('g:i A',$first).' — Late '.$late_minutes.'m';
            }

            if($period==='evening'){
                // Evening: Early only when the employee has punched OUT before End Time - Grace Minutes.
                if($last!==null && $sched_out && $last<$sched_out-($grace*60)){
                    $early_minutes=max(1,(int)floor((($sched_out-$grace*60)-$last)/60));
                    $summary['early']++;
                    $summary['early_names'][]=(string)$emp->name.' — '.date('g:i A',$last).' — Early '.$early_minutes.'m';
                }

                // Required working minutes follow each employee's own Start/End Time.
                // Organisation Grace Minutes are also allowed against required duration.
                if($last!==null && $sched_in && $sched_out){
                    $scheduled_minutes=max(1,(int)round(($sched_out-$sched_in)/60));
                    $required_minutes=max(1,$scheduled_minutes-$standard_break);
                    $minimum_minutes=max(1,$required_minutes-$grace);
                    if($gross<$minimum_minutes){
                        $summary['less']++;
                        $h=intdiv($gross,60); $m=$gross%60;
                        $short=max(1,$minimum_minutes-$gross);
                        $summary['less_names'][]=(string)$emp->name.' — '.$h.'h '.str_pad((string)$m,2,'0',STR_PAD_LEFT).'m — Less '.$short.'m';
                    }
                }
            }
        }
        return $summary;
    }

    private function attendance_summary_font($bold=false){
        $candidates=$bold?[
            '/usr/share/fonts/truetype/msttcorefonts/Arial_Bold.ttf',
            '/usr/share/fonts/truetype/msttcorefonts/Arial Bold.ttf',
            '/usr/share/fonts/truetype/msttcorefonts/arialbd.ttf',
            '/usr/share/fonts/truetype/msttcorefonts/ARIALBD.TTF',
            '/usr/share/fonts/truetype/msttcorefonts/Arial-Bold.ttf',
            '/usr/local/share/fonts/Arial Bold.ttf',
            'C:/Windows/Fonts/arialbd.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf'
        ]:[
            '/usr/share/fonts/truetype/msttcorefonts/Arial.ttf',
            '/usr/share/fonts/truetype/msttcorefonts/Arial Regular.ttf',
            '/usr/share/fonts/truetype/msttcorefonts/arial.ttf',
            '/usr/share/fonts/truetype/msttcorefonts/ARIAL.TTF',
            '/usr/share/fonts/truetype/msttcorefonts/Arial-Regular.ttf',
            '/usr/local/share/fonts/Arial.ttf',
            'C:/Windows/Fonts/arial.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf'
        ];
        foreach($candidates as $font) if(@is_readable($font)) return $font;
        return '';
    }

    private function attendance_summary_text($im,$size,$x,$y,$text,$color,$bold=false){
        $font=$this->attendance_summary_font($bold);
        if($font && function_exists('imagettftext')){
            imagettftext($im,$size,0,$x,$y,$color,$font,(string)$text);
            return;
        }
        imagestring($im,$bold?5:4,$x,max(0,$y-18),(string)$text,$color);
    }

    private function attendance_summary_time($period='morning',$display=false){
        $key=$period==='evening'?'bb_attendance_whatsapp_evening_time':'bb_attendance_whatsapp_morning_time';
        $default=$period==='evening'?'20:00':'11:00';
        $value=(string)get_option($key,$default);
        if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$value)) $value=$default;
        if(!$display) return $value;
        $dt=DateTime::createFromFormat('H:i',$value,new DateTimeZone('Asia/Kolkata'));
        return $dt?$dt->format('g:i A'):$value;
    }

    private function attendance_summary_image($period='morning'){
        if(!function_exists('imagecreatetruecolor')) return '';
        $s=$this->attendance_summary_snapshot($period);

        // Mobile-first WhatsApp image: large Arial text, compact tables, strong hierarchy.
        $w=1080;
        $row_h=78;
        $table_head_h=72;
        $section_title_h=66;
        $section_gap=34;
        $top_h=170;
        $cards_top=198;
        $cards_h=205;

        $split_row=function($text){
            $text=(string)$text;
            $text=str_replace(["â€”","â€“","Â—","Â–"],['—','—','—','—'],$text);
            $parts=preg_split('/\s+—\s+/u',$text);
            if(!$parts || count($parts)<2) $parts=preg_split('/\s+-\s+/u',$text);
            return array_values(array_map('trim',$parts?:[$text]));
        };

        $tables=[];
        $abs=[]; foreach($s['absent_names'] as $name) $abs[]=[(string)$name,'Absent'];
        $tables[]=['Absent',['Employee','Status'],$abs,'red'];
        if($period==='morning'){
            $rows=[]; foreach($s['late_names'] as $entry){ $p=$split_row($entry); $rows[]=[($p[0]??''),($p[1]??''),isset($p[2])?preg_replace('/^Late\s+/i','',$p[2]):'']; }
            $tables[]=['Late',['Employee','In Time','Late By'],$rows,'orange'];
        }else{
            $rows=[]; foreach($s['early_names'] as $entry){ $p=$split_row($entry); $rows[]=[($p[0]??''),($p[1]??''),isset($p[2])?preg_replace('/^Early\s+/i','',$p[2]):'']; }
            $tables[]=['Early',['Employee','Out Time','Early By'],$rows,'orange'];
            $rows=[]; foreach($s['less_names'] as $entry){ $p=$split_row($entry); $rows[]=[($p[0]??''),($p[1]??''),isset($p[2])?preg_replace('/^Less\s+/i','',$p[2]):'']; }
            $tables[]=['Less Working Hours',['Employee','Working Hours','Less By'],$rows,'blue'];
        }
        if(!empty($s['leave_names'])){
            $rows=[]; foreach($s['leave_names'] as $name) $rows[]=[(string)$name,'On Leave'];
            $tables[]=['On Leave',['Employee','Status'],$rows,'purple'];
        }

        $content_h=$cards_top+$cards_h+40;
        foreach($tables as $t){
            $rows=max(1,min(12,count($t[2])));
            $content_h += $section_title_h + $table_head_h + ($rows*$row_h) + $section_gap;
        }
        $h=max(920,min(2200,$content_h+28));
        $im=imagecreatetruecolor($w,$h);

        $white=imagecolorallocate($im,255,255,255);
        $navy=imagecolorallocate($im,15,57,96);
        $green=imagecolorallocate($im,0,158,84);
        $red=imagecolorallocate($im,226,36,45);
        $orange=imagecolorallocate($im,245,137,0);
        $blue=imagecolorallocate($im,24,102,214);
        $purple=imagecolorallocate($im,122,55,210);
        $muted=imagecolorallocate($im,239,246,252);
        $dark=imagecolorallocate($im,14,25,50);
        $line=imagecolorallocate($im,184,199,216);
        $headerbg=imagecolorallocate($im,234,243,251);

        imagefilledrectangle($im,0,0,$w,$h,$white);
        imagefilledrectangle($im,0,0,$w,$top_h,$navy);

        // Header, matching approved mockup proportions.
        $this->attendance_summary_text($im,28,34,55,'WorkBoard Attendance',$white,true);
        $this->attendance_summary_text($im,42,34,118,'Daily Attendance Summary - '.$this->attendance_summary_time($period,true),$white,true);
        $this->attendance_summary_text($im,27,850,83,date('d/m/Y',strtotime($s['date'])),$white,true);

        if($period==='morning'){
            $cards=[['Total',$s['total'],$blue],['Present',$s['present'],$green],['Absent',$s['absent'],$red],['Late',$s['late'],$orange],['On Leave',$s['leave'],$purple]];
        }else{
            $cards=[['Total',$s['total'],$blue],['Present',$s['present'],$green],['Absent',$s['absent'],$red],['Early',$s['early'],$orange],['Less Hours',$s['less'],$blue],['On Leave',$s['leave'],$purple]];
        }

        $gap=10; $available=$w-28; $cw=(int)floor(($available-($gap*(count($cards)-1)))/count($cards)); $x=14;
        foreach($cards as $c){
            imagefilledrectangle($im,$x,$cards_top,$x+$cw,$cards_top+$cards_h,$muted);
            imagerectangle($im,$x,$cards_top,$x+$cw,$cards_top+$cards_h,$muted);
            $label=(string)$c[0];
            $label_size=($period==='evening' && $label==='Less Hours')?25:28;
            $this->attendance_summary_text($im,$label_size,$x+14,$cards_top+62,$label,$dark,true);
            $this->attendance_summary_text($im,64,$x+14,$cards_top+163,(string)$c[1],$c[2],true);
            $x+=$cw+$gap;
        }

        $color_map=['red'=>$red,'orange'=>$orange,'blue'=>$blue,'purple'=>$purple];
        $y=$cards_top+$cards_h+65;
        foreach($tables as $table){
            [$title,$headers,$rows,$ck]=$table;
            $accent=$color_map[$ck]??$blue;
            $this->attendance_summary_text($im,42,20,$y,$title.' ('.count($rows).')',$accent,true);
            $y+=28;

            $left=18; $right=$w-18; $cols=count($headers);
            if($cols===2) $widths=[0.62,0.38]; else $widths=[0.48,0.28,0.24];
            $xpos=[$left]; $usable=$right-$left;
            foreach($widths as $frac) $xpos[]=$xpos[count($xpos)-1]+(int)round($usable*$frac);

            imagefilledrectangle($im,$left,$y,$right,$y+$table_head_h,$headerbg);
            imagerectangle($im,$left,$y,$right,$y+$table_head_h,$line);
            for($i=1;$i<$cols;$i++) imageline($im,$xpos[$i],$y,$xpos[$i],$y+$table_head_h,$line);
            for($i=0;$i<$cols;$i++) $this->attendance_summary_text($im,29,$xpos[$i]+16,$y+48,$headers[$i],$dark,true);
            $y+=$table_head_h;

            if(!$rows) $rows=[['None',...array_fill(0,$cols-1,'')]];
            foreach(array_slice($rows,0,12) as $row){
                imagefilledrectangle($im,$left,$y,$right,$y+$row_h,$white);
                imagerectangle($im,$left,$y,$right,$y+$row_h,$line);
                for($i=1;$i<$cols;$i++) imageline($im,$xpos[$i],$y,$xpos[$i],$y+$row_h,$line);
                for($i=0;$i<$cols;$i++){
                    $txt=(string)($row[$i]??'');
                    $txt=str_replace(["â€”","â€“","Â—","Â–","•","â€¢"],['-','-','-','-','-','-'],$txt);
                    $size=28;
                    if($i===0 && mb_strlen($txt)>24) $size=24;
                    elseif($i===0 && mb_strlen($txt)>18) $size=26;
                    $this->attendance_summary_text($im,$size,$xpos[$i]+16,$y+52,$txt,$dark,false);
                }
                $y+=$row_h;
                if($y>$h-70) break 2;
            }
            $y+=$section_gap;
        }

        $uploads=wp_upload_dir();
        $dir=trailingslashit($uploads['basedir']).'workboard-attendance';
        if(!wp_mkdir_p($dir)){imagedestroy($im);return '';}
        $base='attendance-summary-'.$s['date'].'-'.$period.'-'.$this->attendance_ist_now('His').'-'.substr(wp_hash(microtime(true).wp_rand()),0,8).'.png';
        $file=wp_unique_filename($dir,$base);
        $path=trailingslashit($dir).$file;
        imagepng($im,$path,6);
        imagedestroy($im);
        return trailingslashit($uploads['baseurl']).'workboard-attendance/'.$file;
    }

    public function ajax_test_attendance_whatsapp(){
        $this->verify(); $this->require_manage();
        $mobile=preg_replace('/[^0-9+]/','',(string)($_POST['mobile']??get_option('bb_attendance_whatsapp_test_number',''))); if(!$mobile) wp_send_json_error(['message'=>'Enter a test WhatsApp number'],422);
        $period=sanitize_key((string)($_POST['period']??'both')); if(!in_array($period,['morning','evening','both'],true))$period='both';
        $periods=$period==='both'?['morning','evening']:[$period];
        $results=[]; $errors=[]; $ids=[];
        foreach($periods as $send_period){
            $image=$this->attendance_summary_image($send_period);
            if(!$image){ $errors[]='Could not generate '.ucfirst($send_period).' attendance summary image.'; continue; }
            $label=($send_period==='morning'?'Morning Summary - ':'Evening Summary - ').$this->attendance_summary_time($send_period,true);
            $r=$this->send_meta_whatsapp($mobile,'Daily Attendance Summary',$label,'attendance_summary',true,0,null,0,'attendance-test-'.$send_period.'-'.$this->attendance_ist_now('YmdHis').'-'.wp_rand(100,999),$image);
            if(!empty($r['ok'])){
                $mid=(string)($r['meta_message_id']??''); if($mid)$ids[]=$mid;
                $results[]=['period'=>$send_period,'ok'=>true,'meta_message_id'=>$mid,'image_url'=>$image];
            }else{
                $errors[]=ucfirst($send_period).': '.(string)($r['message']??'WhatsApp test failed');
                $results[]=['period'=>$send_period,'ok'=>false,'error'=>(string)($r['message']??'WhatsApp test failed')];
            }
        }
        if(!$errors && count($results)===count($periods)){
            $mid=(string)end($ids);
            update_option('bb_attendance_whatsapp_last_test_status','pending');
            update_option('bb_attendance_whatsapp_last_test_message_id',$mid);
            update_option('bb_attendance_whatsapp_last_test_error','');
            $msg=$period==='both'?'Morning & Evening tests submitted to Meta — Delivery Pending':'Submitted to Meta — Delivery Pending';
            wp_send_json_success(['message'=>$msg,'status'=>'pending','meta_message_id'=>$mid,'meta_message_ids'=>$ids,'results'=>$results]);
        }
        $err=implode(' | ',$errors);
        update_option('bb_attendance_whatsapp_last_test_status','failed');
        update_option('bb_attendance_whatsapp_last_test_message_id',!empty($ids)?(string)end($ids):'');
        update_option('bb_attendance_whatsapp_last_test_error',$err);
        wp_send_json_error(['message'=>$err?:'Attendance WhatsApp test failed','status'=>'failed','error'=>$err,'results'=>$results],500);
    }

    public function ajax_get_attendance_whatsapp_test_status(){
        $this->verify(); $this->require_manage();
        $mid=sanitize_text_field((string)($_POST['meta_message_id']??get_option('bb_attendance_whatsapp_last_test_message_id','')));
        $status=sanitize_key((string)get_option('bb_attendance_whatsapp_last_test_status','pending'));
        $error=(string)get_option('bb_attendance_whatsapp_last_test_error','');
        if($mid && $mid!==(string)get_option('bb_attendance_whatsapp_last_test_message_id','')){
            global $wpdb; $row=$wpdb->get_row($wpdb->prepare("SELECT status,error_message FROM {$this->network_tables['message_log']} WHERE blog_id=%d AND meta_message_id=%s ORDER BY id DESC LIMIT 1",get_current_blog_id(),$mid));
            if($row){$status=sanitize_key((string)$row->status);$error=(string)$row->error_message;}
        }
        $labels=['pending'=>'Submitted to Meta — Delivery Pending','sent'=>'Sent','delivered'=>'Delivered','read'=>'Read','failed'=>'Failed'];
        wp_send_json_success(['status'=>$status,'label'=>$labels[$status]??ucfirst($status),'meta_message_id'=>$mid,'error'=>$error]);
    }

    private function maybe_send_attendance_summary(){
        if(!(int)get_option('bb_attendance_whatsapp_active',0)) return;
        $now=new DateTime('now',new DateTimeZone('Asia/Kolkata')); $date=$now->format('Y-m-d'); $now_minutes=((int)$now->format('H')*60)+(int)$now->format('i');
        $period='';
        foreach(['morning','evening'] as $candidate){
            $enabled=(int)get_option($candidate==='morning'?'bb_attendance_whatsapp_morning':'bb_attendance_whatsapp_evening',1);
            if(!$enabled) continue;
            $parts=array_map('intval',explode(':',$this->attendance_summary_time($candidate,false)));
            $target=($parts[0]*60)+$parts[1];
            if($now_minutes >= $target && $now_minutes < ($target+15)){ $period=$candidate; break; }
        }
        if(!$period)return;
        $sent_key='bb_attendance_summary_sent_'.$period.'_'.$date; if(get_option($sent_key,0))return;
        $mobile=(string)get_option('bb_attendance_whatsapp_number',''); if(!$mobile)return;
        $image=$this->attendance_summary_image($period); if(!$image)return; $label=($period==='morning'?'Morning Summary - ':'Evening Summary - ').$this->attendance_summary_time($period,true);
        $r=$this->send_meta_whatsapp($mobile,'Daily Attendance Summary',$label,'attendance_summary',false,0,null,0,'attendance-'.$period.'-'.$date,$image); if(!empty($r['ok']))update_option($sent_key,1,false);
    }

    public function ajax_save_attendance_settings(){
        $this->verify(); $this->require_manage();
        $in=sanitize_text_field($_POST['default_in_time']??'09:00'); $out=sanitize_text_field($_POST['default_out_time']??'18:00');
        $cutoff=sanitize_text_field($_POST['missing_out_cutoff']??'23:55');
        foreach([['In Time',$in],['Out Time',$out],['Missing OUT Cut-off',$cutoff]] as $pair){ if(!preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/',$pair[1])) wp_send_json_error(['message'=>'Enter a valid '.$pair[0]],422); }
        $grace=max(0,min(240,absint($_POST['grace_minutes']??15)));
        $standard_break=max(0,min(240,absint($_POST['standard_break_minutes']??45)));
        $auto_break_after=max(0,min(1440,absint($_POST['auto_break_after_minutes']??360)));
        $weekly=trim(sanitize_text_field($_POST['default_weekly_off']??'Sunday'))?:'Sunday';
        $mode=sanitize_key($_POST['missing_out_cutoff_mode']??'same_day'); if(!in_array($mode,['same_day','next_day'],true)) $mode='same_day';
        $salary=sanitize_key($_POST['salary_basis']??'calendar_days'); if(!in_array($salary,['calendar_days','fixed_30','working_days','paid_days'],true)) $salary='calendar_days';
        $location_mode=sanitize_key($_POST['location_mode']??'optional'); if(!in_array($location_mode,['off','optional','mandatory'],true)) $location_mode='optional';
        update_option('bb_attendance_default_in_time',$in); update_option('bb_attendance_default_out_time',$out); update_option('bb_attendance_grace_minutes',$grace);
        update_option('bb_attendance_standard_break_minutes',$standard_break); update_option('bb_attendance_auto_break_after_minutes',$auto_break_after); update_option('bb_attendance_missing_out_cutoff',$cutoff);
        update_option('bb_attendance_missing_out_cutoff_mode',$mode); update_option('bb_attendance_default_weekly_off',$weekly); update_option('bb_attendance_salary_basis',$salary); update_option('bb_attendance_location_mode',$location_mode);
        $wa=!empty($_POST['whatsapp_summary_active'])?1:0; $wm=!empty($_POST['whatsapp_morning'])?1:0; $we=!empty($_POST['whatsapp_evening'])?1:0;
        $wmt=sanitize_text_field($_POST['whatsapp_morning_time']??'11:00'); $wet=sanitize_text_field($_POST['whatsapp_evening_time']??'20:00');
        foreach([['Morning Summary Time',$wmt],['Evening Summary Time',$wet]] as $pair){ if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$pair[1])) wp_send_json_error(['message'=>'Enter a valid '.$pair[0]],422); }
        $wn=preg_replace('/[^0-9+]/','',(string)($_POST['whatsapp_number']??'')); $wtn=preg_replace('/[^0-9+]/','',(string)($_POST['whatsapp_test_number']??''));
        $wt=sanitize_key((string)($_POST['whatsapp_template']??'workboard_daily_attendance_summary')) ?: 'workboard_daily_attendance_summary';
        update_option('bb_attendance_whatsapp_active',$wa); update_option('bb_attendance_whatsapp_number',$wn); update_option('bb_attendance_whatsapp_morning',$wm); update_option('bb_attendance_whatsapp_morning_time',$wmt); update_option('bb_attendance_whatsapp_evening',$we); update_option('bb_attendance_whatsapp_evening_time',$wet); update_option('bb_attendance_whatsapp_template',$wt); update_option('bb_attendance_whatsapp_test_number',$wtn);
        wp_send_json_success(['settings'=>['defaultInTime'=>$in,'defaultOutTime'=>$out,'graceMinutes'=>$grace,'standardBreakMinutes'=>$standard_break,'autoBreakAfterMinutes'=>$auto_break_after,'missingOutCutoff'=>$cutoff,'missingOutCutoffMode'=>$mode,'defaultWeeklyOff'=>$weekly,'salaryBasis'=>$salary,'locationMode'=>$location_mode,'whatsappSummaryActive'=>$wa,'whatsappNumber'=>$wn,'whatsappMorning'=>$wm,'whatsappMorningTime'=>$wmt,'whatsappEvening'=>$we,'whatsappEveningTime'=>$wet,'whatsappTemplate'=>$wt,'whatsappTestNumber'=>$wtn]]);
    }

    public function ajax_save_attendance_payroll_adjustment(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $employee_id=absint($_POST['employee_id']??0);
        $date=sanitize_text_field($_POST['attendance_date']??'');
        $type=sanitize_key($_POST['adjustment_type']??'');
        $minutes=absint($_POST['minutes']??0);
        $reason=trim(sanitize_text_field($_POST['reason']??''));
        $employee=$this->attendance_employee($employee_id);
        if(!$employee_id || !$employee) wp_send_json_error(['message'=>'Select a valid User'],422);
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date) || $date>$this->attendance_ist_now('Y-m-d')) wp_send_json_error(['message'=>'Select a valid current or past date'],422);
        if(!in_array($type,['overtime_approval','manual_credit','manual_deduction'],true)) wp_send_json_error(['message'=>'Select a valid adjustment type'],422);
        if($minutes<1 || $minutes>1440) wp_send_json_error(['message'=>'Minutes must be between 1 and 1440'],422);
        if($reason==='') wp_send_json_error(['message'=>'Reason is required for payroll adjustments'],422);
        if($type==='overtime_approval'){
            $recorded=$this->attendance_recorded_overtime_minutes($employee,$date);
            $already=(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(minutes),0) FROM {$this->tables['attendance_payroll_adjustments']} WHERE employee_id=%d AND attendance_date=%s AND adjustment_type='overtime_approval'",$employee_id,$date));
            $remaining=max(0,$recorded-$already);
            if($minutes>$remaining) wp_send_json_error(['message'=>'Only '.$remaining.' overtime minute(s) remain available for approval'],422);
        }
        $signed_minutes=$type==='manual_deduction'?-1*$minutes:$minutes;
        $actor=$this->current_employee();
        $data=['employee_id'=>$employee_id,'attendance_date'=>$date,'adjustment_type'=>$type,'minutes'=>$signed_minutes,'reason'=>$reason,'created_by_employee_id'=>$actor?(int)$actor->id:null,'created_by_wp_user_id'=>get_current_user_id()?:null,'created_at'=>current_time('mysql')];
        if($wpdb->insert($this->tables['attendance_payroll_adjustments'],$data)===false) wp_send_json_error(['message'=>'Could not save payroll adjustment']);
        $labels=['overtime_approval'=>'Overtime approved','manual_credit'=>'Hours credited','manual_deduction'=>'Hours deducted'];
        $this->log_activity('attendance_payroll_adjustment',($employee->name?:'User').' · '.$date.' · '.$labels[$type].' · '.$minutes.' minutes');
        wp_send_json_success(['id'=>(int)$wpdb->insert_id,'minutes'=>$signed_minutes,'message'=>$labels[$type].' successfully']);
    }

    public function ajax_get_attendance_month(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $month=sanitize_text_field($_POST['month']??'');
        if(!preg_match('/^\\d{4}-\\d{2}$/',$month)) $month=$this->attendance_ist_now('Y-m');
        try{$start=new DateTimeImmutable($month.'-01',$this->attendance_ist_timezone()); $end=$start->modify('last day of this month');}catch(Exception $e){ wp_send_json_error(['message'=>'Invalid month'],422); }
        $from=$start->format('Y-m-d'); $to=$end->format('Y-m-d');
        $events=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tables['attendance_events']} WHERE attendance_date BETWEEN %s AND %s ORDER BY attendance_date,punched_at,id",$from,$to));
        $this->attendance_add_ist_display($events);
        $holidays=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tables['attendance_holidays']} WHERE holiday_date BETWEEN %s AND %s ORDER BY holiday_date",$from,$to));
        $claims=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tables['attendance_weekly_off_claims']} WHERE claim_date BETWEEN %s AND %s ORDER BY claim_date,id",$from,$to));
        $leaves=$wpdb->get_results($wpdb->prepare("SELECT l.*,e.name employee_name,a.name approver_name FROM {$this->tables['attendance_leaves']} l LEFT JOIN {$this->tables['employees']} e ON e.id=l.employee_id LEFT JOIN {$this->tables['employees']} a ON a.id=l.approver_employee_id WHERE l.from_date<=%s AND l.to_date>=%s ORDER BY l.from_date,l.id",$to,$from));
        $adjustments=$wpdb->get_results($wpdb->prepare("SELECT a.*,e.name created_by_name FROM {$this->tables['attendance_payroll_adjustments']} a LEFT JOIN {$this->tables['employees']} e ON e.id=a.created_by_employee_id WHERE a.attendance_date BETWEEN %s AND %s ORDER BY a.attendance_date,a.id",$from,$to));
        wp_send_json_success(['month'=>$month,'from'=>$from,'to'=>$to,'events'=>$events,'holidays'=>$holidays,'claims'=>$claims,'leaves'=>$leaves,'adjustments'=>$adjustments,'attendanceNow'=>$this->attendance_ist_now('Y-m-d H:i:s')]);
    }

    public function ajax_punch_attendance(){
        $this->verify(); global $wpdb;
        $this->ensure_attendance_live_punch_schema();
        $employee_id=absint($_POST['employee_id']??0);
        $employee=$this->attendance_employee($employee_id);
        if(!$this->attendance_employee_is_enabled($employee)) wp_send_json_error(['message'=>'Attendance is not active for this User'],422);
        if(!$this->attendance_can_punch_employee($employee_id)) wp_send_json_error(['message'=>'You do not have permission to punch attendance for this User'],403);
        $date=$this->attendance_ist_now('Y-m-d'); $events=$this->attendance_day_events($employee_id,$date);
        $type=sanitize_key($_POST['event_type']??'main'); if(!in_array($type,['main','combo'],true)) $type='main';
        $direction=strtolower(sanitize_key($_POST['direction']??'')); if(!in_array($direction,['in','out'],true)) wp_send_json_error(['message'=>'Invalid attendance action'],422);
        $combo_id=0; $label='';
        $main_last=$this->attendance_last_direction($events,'main',0);
        if($type==='main'){
            $expected=(!$main_last || $main_last==='out')?'in':'out';
            if($direction!==$expected) wp_send_json_error([
                'message'=>'Attendance was refreshed. Please use the updated '.$expected.' action.',
                'expected_direction'=>$expected,
                'employee_id'=>$employee_id,
                'attendance_date'=>$date
            ],409);
            if($direction==='out' && ($open=$this->attendance_open_combo($events))){
                $combo=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['attendance_combos']} WHERE id=%d",$open));
                wp_send_json_error(['message'=>'Complete '.($combo?$combo->in_label:'the active return action').' first.'],422);
            }
            $label=$direction==='in'?'In':'Out';
        }else{
            if($main_last!=='in') wp_send_json_error(['message'=>'Mark IN first before using an Out/In combo.'],422);
            $combo_id=absint($_POST['combo_id']??0);
            $combo=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['attendance_combos']} WHERE id=%d AND status='active'",$combo_id));
            if(!$combo) wp_send_json_error(['message'=>'Attendance combo is not active'],422);
            $open=$this->attendance_open_combo($events);
            $last=$this->attendance_last_direction($events,'combo',$combo_id);
            $expected=($last==='out')?'in':'out';
            if($direction!==$expected) wp_send_json_error(['message'=>'Combo state changed. Please refresh and try again.'],409);
            if($direction==='out' && $open && $open!==$combo_id) wp_send_json_error(['message'=>'Complete the current Out/In combo before starting another one.'],422);
            $label=$direction==='out'?$combo->out_label:$combo->in_label;
        }
        // v4.13.97: live punches can carry fresh browser/device geolocation.
        $location_mode=(string)get_option('bb_attendance_location_mode','optional');
        if(!in_array($location_mode,['off','optional','mandatory'],true)) $location_mode='optional';
        $lat_raw=isset($_POST['latitude'])?trim((string)wp_unslash($_POST['latitude'])):'';
        $lng_raw=isset($_POST['longitude'])?trim((string)wp_unslash($_POST['longitude'])):'';
        $acc_raw=isset($_POST['location_accuracy'])?trim((string)wp_unslash($_POST['location_accuracy'])):'';
        $latitude=null; $longitude=null; $location_accuracy=null;
        if($location_mode!=='off' && $lat_raw!=='' && $lng_raw!=='' && is_numeric($lat_raw) && is_numeric($lng_raw)){
            $lat=(float)$lat_raw; $lng=(float)$lng_raw;
            if($lat>=-90 && $lat<=90 && $lng>=-180 && $lng<=180){
                $latitude=$lat; $longitude=$lng;
                if($acc_raw!=='' && is_numeric($acc_raw)) $location_accuracy=max(0,(float)$acc_raw);
            }
        }
        if($location_mode==='mandatory'){
            if($latitude===null || $longitude===null) wp_send_json_error(['message'=>'Location is required to mark attendance. Please allow location access and try again.'],422);
            if($latitude===null || $longitude===null) wp_send_json_error(['message'=>'Attendance requires a current location. Please enable Location Services and try again.'],422);
        }
        // Save the punch immediately. Readable address resolution now runs asynchronously so a slow
        // reverse-geocoding service never delays the employee's IN/OUT action.
        $actor=$this->current_employee();
        $data=['employee_id'=>$employee_id,'attendance_date'=>$date,'event_type'=>$type,'combo_id'=>$combo_id?:null,'direction'=>$direction,'action_label'=>$label,'punched_at'=>current_time('mysql'),'created_by_employee_id'=>$actor?(int)$actor->id:null,'created_by_wp_user_id'=>get_current_user_id()?:null,'source'=>'web','latitude'=>$latitude,'longitude'=>$longitude,'location_accuracy'=>$location_accuracy,'location_address'=>null,'created_at'=>current_time('mysql')];
        if($wpdb->insert($this->tables['attendance_events'],$data)===false){
            // One repair/retry protects upgraded sites whose attendance table pre-dates
            // Supervisor punching / location columns.
            $this->ensure_attendance_live_punch_schema();
            if($wpdb->insert($this->tables['attendance_events'],$data)===false){
                wp_send_json_error(['message'=>'Could not record attendance. Please refresh and try again.']);
            }
        }
        $event_id=(int)$wpdb->insert_id;
        if($latitude!==null && $longitude!==null){
            if(!wp_next_scheduled('bb_attendance_reverse_geocode_event',[$event_id])) wp_schedule_single_event(time()+1,'bb_attendance_reverse_geocode_event',[$event_id]);
            if(function_exists('spawn_cron')) spawn_cron(time());
        }
        $this->log_activity('attendance_punch',$employee->name.' · '.$label);
        wp_send_json_success(['event_id'=>$event_id,'label'=>$label,'time'=>$this->attendance_ist_now('H:i'),'timezone'=>'Asia/Kolkata']);
    }
    // v4.13.94: Manager/Owner/Admin can backfill attendance for any current/past date.
    // This intentionally does not enforce the live punch sequence because it is a correction/backfill tool.
    public function ajax_add_attendance_event(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $employee_id=absint($_POST['employee_id']??0);
        $employee=$this->attendance_employee($employee_id);
        if(!$this->attendance_employee_is_enabled($employee)) wp_send_json_error(['message'=>'Attendance is not active for this User'],422);
        $date=sanitize_text_field($_POST['attendance_date']??'');
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) wp_send_json_error(['message'=>'Select a valid attendance date'],422);
        $today=$this->attendance_ist_now('Y-m-d');
        if($date>$today) wp_send_json_error(['message'=>'Future attendance cannot be marked'],422);
        if(!empty($employee->joined_on) && $date<(string)$employee->joined_on) wp_send_json_error(['message'=>'Attendance date cannot be before the User joining date'],422);
        $time=sanitize_text_field($_POST['time']??'');
        if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$time)) wp_send_json_error(['message'=>'Enter a valid attendance time'],422);
        $event_key=sanitize_text_field($_POST['event_key']??'main:in');
        $parts=explode(':',$event_key);
        $type=sanitize_key($parts[0]??'main');
        $combo_id=0; $direction=''; $label='';
        if($type==='main'){
            $direction=strtolower(sanitize_key($parts[1]??''));
            if(!in_array($direction,['in','out'],true)) wp_send_json_error(['message'=>'Select a valid attendance action'],422);
            $label=$direction==='in'?'In':'Out';
        }elseif($type==='combo'){
            $combo_id=absint($parts[1]??0);
            $direction=strtolower(sanitize_key($parts[2]??''));
            if(!$combo_id || !in_array($direction,['in','out'],true)) wp_send_json_error(['message'=>'Select a valid attendance action'],422);
            $combo=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['attendance_combos']} WHERE id=%d LIMIT 1",$combo_id));
            if(!$combo) wp_send_json_error(['message'=>'Attendance combo not found'],404);
            $label=$direction==='out'?(string)$combo->out_label:(string)$combo->in_label;
        }else wp_send_json_error(['message'=>'Select a valid attendance action'],422);
        $punched_at=$this->attendance_ist_to_storage($date,$time);
        $duplicate=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tables['attendance_events']} WHERE employee_id=%d AND attendance_date=%s AND event_type=%s AND COALESCE(combo_id,0)=%d AND direction=%s AND punched_at=%s",
            $employee_id,$date,$type,$combo_id,$direction,$punched_at
        ));
        if($duplicate) wp_send_json_error(['message'=>'The same attendance entry already exists'],409);
        $actor=$this->current_employee();
        $data=['employee_id'=>$employee_id,'attendance_date'=>$date,'event_type'=>$type,'combo_id'=>$combo_id?:null,'direction'=>$direction,'action_label'=>$label,'punched_at'=>$punched_at,'created_by_employee_id'=>$actor?(int)$actor->id:null,'created_by_wp_user_id'=>get_current_user_id()?:null,'source'=>'manual','created_at'=>current_time('mysql')];
        if($wpdb->insert($this->tables['attendance_events'],$data)===false) wp_send_json_error(['message'=>'Could not add attendance entry']);
        $event_id=(int)$wpdb->insert_id;
        $reason=trim(sanitize_text_field($_POST['reason']??'')); if(!$reason) $reason='Manual attendance added by Manager';
        $wpdb->insert($this->tables['attendance_audit'],['event_id'=>$event_id,'employee_id'=>$employee_id,'action'=>'manual_added','old_punched_at'=>null,'new_punched_at'=>$punched_at,'reason'=>$reason,'changed_by_employee_id'=>$actor?(int)$actor->id:null,'changed_by_wp_user_id'=>get_current_user_id()?:null,'created_at'=>current_time('mysql')]);
        $this->log_activity('attendance_manual_added',$employee->name.' · '.$label.' · '.$date.' '.$time);
        wp_send_json_success(['event_id'=>$event_id,'employee_id'=>$employee_id,'attendance_date'=>$date,'label'=>$label,'time'=>$time]);
    }

    public function ajax_update_attendance_event(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $id=absint($_POST['id']??0); $ev=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['attendance_events']} WHERE id=%d",$id));
        if(!$ev) wp_send_json_error(['message'=>'Attendance entry not found'],404);
        $time=sanitize_text_field($_POST['time']??''); if(!preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/',$time)) wp_send_json_error(['message'=>'Enter a valid time'],422);
        $reason=trim(sanitize_text_field($_POST['reason']??'')); if(!$reason) wp_send_json_error(['message'=>'Reason is required for an attendance correction'],422);
        $new=$this->attendance_ist_to_storage($ev->attendance_date,$time); $actor=$this->current_employee();
        $wpdb->insert($this->tables['attendance_audit'],['event_id'=>$id,'employee_id'=>$ev->employee_id,'action'=>'time_changed','old_punched_at'=>$ev->punched_at,'new_punched_at'=>$new,'reason'=>$reason,'changed_by_employee_id'=>$actor?(int)$actor->id:null,'changed_by_wp_user_id'=>get_current_user_id()?:null,'created_at'=>current_time('mysql')]);
        $wpdb->update($this->tables['attendance_events'],['punched_at'=>$new],['id'=>$id]);
        wp_send_json_success();
    }
    public function ajax_delete_attendance_event(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $id=absint($_POST['id']??0); $ev=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['attendance_events']} WHERE id=%d",$id));
        if(!$ev) wp_send_json_error(['message'=>'Attendance entry not found'],404);
        // v4.13.95: deletion is intentionally quick for Manager/Owner/Admin; no reason is required.
        $actor=$this->current_employee();
        $wpdb->insert($this->tables['attendance_audit'],['event_id'=>$id,'employee_id'=>$ev->employee_id,'action'=>'deleted','old_punched_at'=>$ev->punched_at,'new_punched_at'=>null,'reason'=>'','changed_by_employee_id'=>$actor?(int)$actor->id:null,'changed_by_wp_user_id'=>get_current_user_id()?:null,'created_at'=>current_time('mysql')]);
        $wpdb->delete($this->tables['attendance_events'],['id'=>$id]);
        wp_send_json_success();
    }

    // v4.13.95: one-click Manager/Owner/Admin deletion of all punches for one employee/date.
    public function ajax_delete_attendance_day(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $employee_id=absint($_POST['employee_id']??0);
        $date=sanitize_text_field($_POST['attendance_date']??'');
        if(!$employee_id || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) wp_send_json_error(['message'=>'Invalid attendance date'],422);
        $employee=$this->attendance_employee($employee_id);
        if(!$employee) wp_send_json_error(['message'=>'User not found'],404);
        $events=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tables['attendance_events']} WHERE employee_id=%d AND attendance_date=%s ORDER BY id",$employee_id,$date));
        if(!$events) wp_send_json_error(['message'=>'No attendance punches found for this date'],404);
        $actor=$this->current_employee();
        // Keep only one minimal backend audit marker for the whole-day deletion.
        $wpdb->insert($this->tables['attendance_audit'],['event_id'=>null,'employee_id'=>$employee_id,'action'=>'day_deleted','old_punched_at'=>null,'new_punched_at'=>null,'reason'=>'Deleted '.count($events).' punch(es) for '.$date,'changed_by_employee_id'=>$actor?(int)$actor->id:null,'changed_by_wp_user_id'=>get_current_user_id()?:null,'created_at'=>current_time('mysql')]);
        $deleted=$wpdb->query($wpdb->prepare("DELETE FROM {$this->tables['attendance_events']} WHERE employee_id=%d AND attendance_date=%s",$employee_id,$date));
        if($deleted===false) wp_send_json_error(['message'=>'Could not delete attendance'],500);
        $this->log_activity('attendance_day_deleted',$employee->name.' · '.$date);
        wp_send_json_success(['deleted'=>(int)$deleted,'employee_id'=>$employee_id,'attendance_date'=>$date]);
    }

    public function ajax_save_party(){
        $this->verify(); $this->require_manage(); global $wpdb; $id=absint($_POST['id']??0); $existing=$id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['parties']} WHERE id=%d",$id)):null;
        $login_enabled=!empty($_POST['login_enabled'])&&get_option('bb_enable_party_share',0)?1:0; $login_id=trim(sanitize_user(wp_unslash($_POST['login_id']??''),true)); $new_password=(string)($_POST['new_password']??'');
        $data=['party_name'=>sanitize_text_field($_POST['party_name']??''),'party_type'=>sanitize_text_field($_POST['party_type']??''),'party_mobile'=>preg_replace('/[^0-9+]/','',wp_unslash($_POST['party_mobile']??'')),'party_email'=>sanitize_email($_POST['party_email']??''),'party_address'=>sanitize_textarea_field($_POST['party_address']??''),'city'=>sanitize_text_field($_POST['city']??''),'state'=>sanitize_text_field($_POST['state']??''),'pin'=>sanitize_text_field($_POST['pin']??''),'login_enabled'=>$login_enabled,'login_id'=>$login_enabled?$login_id:'','status'=>'active','updated_at'=>current_time('mysql')];
        if(!$data['party_name']) wp_send_json_error(['message'=>'Party Name is required'],422); if(!$data['party_type']) wp_send_json_error(['message'=>'Party Type is required'],422); if($data['party_email']&&!is_email($data['party_email'])) wp_send_json_error(['message'=>'Enter a valid Party Email'],422); $wp_user_id=$existing?(int)$existing->wp_user_id:0;
        if($login_enabled){ if(!$login_id) wp_send_json_error(['message'=>'Login ID is required when Party Login is enabled'],422); if(!$id&&$new_password==='') wp_send_json_error(['message'=>'Password is required for a new Party login'],422); $dup_party=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['parties']} WHERE LOWER(login_id)=LOWER(%s) AND id<>%d",$login_id,$id)); $dup_emp=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['employees']} WHERE LOWER(employee_code)=LOWER(%s)",$login_id)); $wp_existing=get_user_by('login',$login_id); if($dup_party||$dup_emp||($wp_existing&&(!$existing||(int)$existing->wp_user_id!==(int)$wp_existing->ID))) wp_send_json_error(['message'=>'This Login ID is already in use'],422); if(!$wp_user_id){ $uid=wp_create_user($login_id,$new_password?:wp_generate_password(20,true,true),$data['party_email']?:($login_id.'@workboard.local')); if(is_wp_error($uid)) wp_send_json_error(['message'=>$uid->get_error_message()],422); $u=new WP_User($uid); $u->set_role('bb_party'); wp_update_user(['ID'=>$uid,'display_name'=>$data['party_name']]); $wp_user_id=(int)$uid; } elseif($new_password!=='') wp_set_password($new_password,$wp_user_id); $data['wp_user_id']=$wp_user_id; } else $data['wp_user_id']=$wp_user_id?:null;
        if(!$id)$data['created_at']=current_time('mysql'); $ok=$id?$wpdb->update($this->tables['parties'],$data,['id'=>$id]):$wpdb->insert($this->tables['parties'],$data); if($ok===false) wp_send_json_error(['message'=>'Could not save Party. Please try again.'],500); $party_id=$id?:$wpdb->insert_id; $this->log_activity($id?'party_updated':'party_created',$data['party_name']);
        $saved_party=$wpdb->get_row($wpdb->prepare("SELECT id,party_name,party_type,party_mobile,party_email,party_address,city,state,pin,login_enabled,login_id,status,wp_user_id,created_at,updated_at FROM {$this->tables['parties']} WHERE id=%d",$party_id),ARRAY_A);
        wp_send_json_success(['party_id'=>(int)$party_id,'party'=>$saved_party,'message'=>$id?'Party updated':'Party added']);
    }

    public function ajax_delete_party(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $id=absint($_POST['id']??0);
        if(!$id) wp_send_json_error(['message'=>'Party not found'],404);
        $party=$wpdb->get_row($wpdb->prepare("SELECT party_name FROM {$this->tables['parties']} WHERE id=%d",$id));
        if(!$party) wp_send_json_success();
        $wpdb->delete($this->tables['work_area_parties'],['party_id'=>$id]);
        $ok=$wpdb->delete($this->tables['parties'],['id'=>$id]);
        if($ok===false) wp_send_json_error(['message'=>'Could not delete Party'],500);
        $this->log_activity('party_deleted',(string)$party->party_name);
        wp_send_json_success();
    }

    public function ajax_save_department(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $id=absint($_POST['id']??0); $name=trim(sanitize_text_field($_POST['name']??'')); $status=sanitize_key($_POST['status']??'active');
        if(!$name) wp_send_json_error(['message'=>'Department name is required']);
        if(!in_array($status,['active','inactive'],true)) $status='active';
        $dup=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['departments']} WHERE LOWER(name)=LOWER(%s) AND id<>%d",$name,$id));
        if($dup) wp_send_json_error(['message'=>'This Department already exists']);
        if($id){
            $old=(string)$wpdb->get_var($wpdb->prepare("SELECT name FROM {$this->tables['departments']} WHERE id=%d",$id));
            $ok=$wpdb->update($this->tables['departments'],['name'=>$name,'status'=>$status,'updated_at'=>current_time('mysql')],['id'=>$id]);
            if($ok===false) wp_send_json_error(['message'=>'Could not update Department']);
            if($old && strcasecmp($old,$name)!==0) $wpdb->update($this->tables['employees'],['department'=>$name],['department'=>$old]);
        }else{
            $code=$this->next_auto_code($this->tables['departments'],'department_code','DEPT');
            $ok=$wpdb->insert($this->tables['departments'],['department_code'=>$code,'name'=>$name,'description'=>'','login_enabled'=>0,'can_complete'=>1,'status'=>$status,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);
            if($ok===false) wp_send_json_error(['message'=>'Could not save Department']);
            $id=(int)$wpdb->insert_id;
        }
        $this->log_activity('department_saved',$name);
        wp_send_json_success(['department_id'=>$id,'name'=>$name,'status'=>$status]);
    }
    public function ajax_delete_department(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $id=absint($_POST['id']??0); $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['departments']} WHERE id=%d",$id));
        if(!$row) wp_send_json_success();
        $used=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['employees']} WHERE LOWER(department)=LOWER(%s)",$row->name));
        $linked_employees=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['employee_departments']} WHERE department_id=%d",$id));
        $linked_workspaces=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['workspace_departments']} WHERE department_id=%d",$id));
        if($used||$linked_employees||$linked_workspaces) wp_send_json_error(['message'=>'This Department is already in use. Set it Inactive instead.'],422);
        $wpdb->delete($this->tables['departments'],['id'=>$id]);
        wp_send_json_success();
    }

    public function ajax_save_designation(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $id=absint($_POST['id']??0); $name=trim(sanitize_text_field($_POST['name']??'')); $status=sanitize_key($_POST['status']??'active');
        if(!$name) wp_send_json_error(['message'=>'Designation name is required']);
        if(!in_array($status,['active','inactive'],true)) $status='active';
        $dup=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['designations']} WHERE LOWER(name)=LOWER(%s) AND id<>%d",$name,$id));
        if($dup) wp_send_json_error(['message'=>'This Designation already exists']);
        if($id){
            $old=(string)$wpdb->get_var($wpdb->prepare("SELECT name FROM {$this->tables['designations']} WHERE id=%d",$id));
            $ok=$wpdb->update($this->tables['designations'],['name'=>$name,'status'=>$status,'updated_at'=>current_time('mysql')],['id'=>$id]);
            if($ok===false) wp_send_json_error(['message'=>'Could not update Designation']);
            if($old && strcasecmp($old,$name)!==0) $wpdb->update($this->tables['employees'],['designation'=>$name],['designation'=>$old]);
        }else{
            $ok=$wpdb->insert($this->tables['designations'],['name'=>$name,'status'=>$status,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);
            if($ok===false) wp_send_json_error(['message'=>'Could not save Designation']);
            $id=(int)$wpdb->insert_id;
        }
        $this->log_activity('designation_saved',$name);
        wp_send_json_success(['designation_id'=>$id,'name'=>$name,'status'=>$status]);
    }
    public function ajax_delete_designation(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $id=absint($_POST['id']??0); $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['designations']} WHERE id=%d",$id));
        if(!$row) wp_send_json_success();
        $used=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['employees']} WHERE LOWER(designation)=LOWER(%s)",$row->name));
        if($used) wp_send_json_error(['message'=>'This Designation is used by '.$used.' User(s). Set it Inactive instead.'],422);
        $wpdb->delete($this->tables['designations'],['id'=>$id]);
        wp_send_json_success();
    }

    private function normalize_label_name($name){
        $name=trim(sanitize_text_field($name));
        return strtolower(preg_replace('/\s+/u',' ',$name));
    }

    private function ensure_label_master_label($name){
        global $wpdb;
        $name=trim(sanitize_text_field($name));
        if($name==='') return 0;
        $normalized=$this->normalize_label_name($name);
        $row=$wpdb->get_row($wpdb->prepare("SELECT id,status FROM {$this->tables['labels']} WHERE normalized_name=%s LIMIT 1",$normalized));
        if($row){
            if((string)$row->status!=='active') $wpdb->update($this->tables['labels'],['status'=>'active','updated_at'=>current_time('mysql')],['id'=>(int)$row->id]);
            return (int)$row->id;
        }
        $wpdb->insert($this->tables['labels'],[
            'name'=>$name,'normalized_name'=>$normalized,'status'=>'active',
            'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')
        ]);
        return (int)$wpdb->insert_id;
    }

    private function sync_label_master_from_tasks(){
        global $wpdb;
        $rows=$wpdb->get_col("SELECT labels FROM {$this->tables['tasks']} WHERE labels IS NOT NULL AND labels<>''");
        foreach($rows as $raw){
            foreach(array_filter(array_map('trim',explode(',',(string)$raw))) as $label) $this->ensure_label_master_label($label);
        }
    }

    private function labels_master_state(){
        global $wpdb;
        $this->sync_label_master_from_tasks();
        $rows=$wpdb->get_results("SELECT id,name,normalized_name,status,created_at,updated_at FROM {$this->tables['labels']} ORDER BY name,id");
        $task_labels=$wpdb->get_col("SELECT labels FROM {$this->tables['tasks']} WHERE labels IS NOT NULL AND labels<>''");
        foreach($rows as $r){
            $needle=$this->normalize_label_name($r->name); $count=0;
            foreach($task_labels as $raw){
                foreach(array_map('trim',explode(',',(string)$raw)) as $part){
                    if($this->normalize_label_name($part)===$needle){$count++;break;}
                }
            }
            $r->usage_count=$count;
        }
        return $rows;
    }

    public function ajax_save_label(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $id=absint($_POST['id']??0);
        $name=trim(sanitize_text_field($_POST['name']??''));
        $status=sanitize_key($_POST['status']??'active');
        if(!$name) wp_send_json_error(['message'=>'Label name is required'],422);
        if(!in_array($status,['active','inactive'],true)) $status='active';
        $normalized=$this->normalize_label_name($name);
        $dup=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['labels']} WHERE normalized_name=%s AND id<>%d",$normalized,$id));
        if($dup) wp_send_json_error(['message'=>'This Label already exists'],422);

        if($id){
            $old=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['labels']} WHERE id=%d",$id));
            if(!$old) wp_send_json_error(['message'=>'Label not found'],404);
            $ok=$wpdb->update($this->tables['labels'],[
                'name'=>$name,'normalized_name'=>$normalized,'status'=>$status,'updated_at'=>current_time('mysql')
            ],['id'=>$id]);
            if($ok===false) wp_send_json_error(['message'=>'Could not update Label'],500);
            if(strcasecmp((string)$old->name,$name)!==0){
                $tasks=$wpdb->get_results("SELECT id,labels FROM {$this->tables['tasks']} WHERE labels IS NOT NULL AND labels<>''");
                $old_norm=$this->normalize_label_name($old->name);
                foreach($tasks as $t){
                    $parts=array_values(array_filter(array_map('trim',explode(',',(string)$t->labels))));
                    $changed=false;
                    foreach($parts as &$part){
                        if($this->normalize_label_name($part)===$old_norm){$part=$name;$changed=true;}
                    }
                    unset($part);
                    if($changed) $wpdb->update($this->tables['tasks'],['labels'=>implode(', ',$parts),'updated_at'=>current_time('mysql')],['id'=>(int)$t->id]);
                }
            }
        }else{
            $ok=$wpdb->insert($this->tables['labels'],[
                'name'=>$name,'normalized_name'=>$normalized,'status'=>$status,
                'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')
            ]);
            if($ok===false) wp_send_json_error(['message'=>'Could not save Label'],500);
            $id=(int)$wpdb->insert_id;
        }
        $this->log_activity('label_saved',$name);
        wp_send_json_success(['label_id'=>$id,'name'=>$name,'status'=>$status]);
    }

    public function ajax_delete_label(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $id=absint($_POST['id']??0);
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['labels']} WHERE id=%d",$id));
        if(!$row) wp_send_json_success();

        // Delete is a true cascade for Labels:
        // remove the Label from every Task first, then remove it from Label Master.
        $needle=$this->normalize_label_name($row->name);
        $tasks=$wpdb->get_results("SELECT id,labels FROM {$this->tables['tasks']} WHERE labels IS NOT NULL AND labels<>''");
        $updated_tasks=0;

        foreach($tasks as $task){
            $parts=array_values(array_filter(array_map('trim',explode(',',(string)$task->labels))));
            $kept=[];
            $changed=false;

            foreach($parts as $part){
                if($this->normalize_label_name($part)===$needle){
                    $changed=true;
                    continue;
                }
                $kept[]=$part;
            }

            if($changed){
                $wpdb->update(
                    $this->tables['tasks'],
                    ['labels'=>implode(', ',$kept),'updated_at'=>current_time('mysql')],
                    ['id'=>(int)$task->id]
                );
                $updated_tasks++;
            }
        }

        $deleted=$wpdb->delete($this->tables['labels'],['id'=>$id]);
        if($deleted===false) wp_send_json_error(['message'=>'Could not delete Label'],500);

        $this->log_activity('label_deleted',(string)$row->name);
        wp_send_json_success([
            'message'=>'Label deleted',
            'tasks_updated'=>$updated_tasks
        ]);
    }

    private function normalize_priority($value){ $p=sanitize_text_field($value); if($p==='') return ''; if($p==='Critical') $p='Urgent'; if($p==='Normal') $p='Medium'; return in_array($p,['Urgent','High','Medium','Low'],true)?$p:''; }

    public function ajax_update_task_reminders(){
        $this->verify(); global $wpdb;
        $task_id=absint($_POST['task_id']??0);
        if(!$task_id) wp_send_json_error(['message'=>'Task not found']);
        $task=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d",$task_id));
        if(!$task) wp_send_json_error(['message'=>'Task not found']);
        if(!$this->task_permission($task_id,'edit')) wp_send_json_error(['message'=>'Only a Task Owner can update reminders'],403);
        $notify_enabled=!empty($_POST['notify_enabled'])?1:0;
        $reminder_enabled=!empty($_POST['reminder_enabled'])?1:0;
        $rules=$reminder_enabled?$this->normalize_posted_reminders($_POST['reminders']??'[]',(string)($task->due_time??'')):[];
        if($reminder_enabled && !$rules) $rules=[['value'=>1,'unit'=>'Hours']];
        if($reminder_enabled && !(int)$task->is_template && $task->task_type!=='Recurring' && empty($task->due_date)) wp_send_json_error(['message'=>'Due Date is required to set a reminder']);
        $after_raw=[
            'enabled'=>!empty($_POST['after_due_enabled'])?1:0,
            'interval_value'=>$_POST['after_due_interval_value']??1,
            'interval_unit'=>$_POST['after_due_interval_unit']??'Hours',
            'active_start'=>$_POST['after_due_active_start']??'08:00',
            'active_end'=>$_POST['after_due_active_end']??'20:00',
            'all_day'=>!empty($_POST['after_due_all_day'])?1:0,
            'duration_days'=>$_POST['after_due_duration_days']??0,
            'max_reminders'=>$_POST['after_due_max_reminders']??0,
        ];
        $after=$this->normalize_overdue_reminder_settings($after_raw,true);
        if($after['enabled'] && $after['interval_unit']==='Minutes' && empty($task->due_time)) wp_send_json_error(['message'=>'Minutes after-due reminder requires Due Time']);
        $data=['notify_enabled'=>$notify_enabled,'reminder_enabled'=>$reminder_enabled?1:0,'updated_at'=>current_time('mysql')];
        if($reminder_enabled && $rules){
            $data['reminder_value']=$rules[0]['value'];
            $data['reminder_unit']=$rules[0]['unit'];
        }
        $ok=$wpdb->update($this->tables['tasks'],$data,['id'=>$task_id]);
        if($ok===false) wp_send_json_error(['message'=>'Could not update reminder']);
        if($reminder_enabled) $this->sync_additional_task_reminders($task_id,$rules);
        else $wpdb->delete($this->tables['task_reminders'],['task_id'=>$task_id]);
        $this->sync_task_overdue_reminder_settings($task_id,$after);
        // v4.14.14: saving/editing reminder settings must never itself send a Due Reminder.
        // Keep the consistency safeguard and ensure the recurring 5-minute cron exists,
        // but do not run the reminder processor or queue a one-off immediate event here.
        if($after['enabled']){
            if(!wp_next_scheduled('bb_task_reminder_tick')) $this->ensure_reminder_cron();
        }
        wp_send_json_success([
            'message'=>'Notification & Reminder updated',
            'reminder_count'=>($reminder_enabled?count($rules):0)+($after['enabled']?1:0),
            'after_due_enabled'=>$after['enabled']
        ]);
    }

    public function ajax_update_task_billing(){
        $this->verify(); global $wpdb;
        if((int)get_option('bb_show_module_billing',0)!==1) wp_send_json_error(['message'=>'Billing module is disabled'],403);
        $task_id=absint($_POST['task_id']??0);
        if(!$task_id) wp_send_json_error(['message'=>'Task is required'],422);
        $task=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d",$task_id));
        if(!$task) wp_send_json_error(['message'=>'Task not found'],404);
        if(!$this->task_permission($task_id,'edit') && !$this->is_privileged_user()) wp_send_json_error(['message'=>'You do not have permission to update billing'],403);
        if((int)($task->is_billable??0)!==1 || (string)($task->status??'')!=='Completed') wp_send_json_error(['message'=>'Only completed billable tasks can be billed'],422);
        if(!isset($_POST['billed_amount']) || $_POST['billed_amount']==='') wp_send_json_error(['message'=>'Billed Amount is required'],422);
        $amount=max(0,(float)$_POST['billed_amount']);
        $ok=$wpdb->update($this->tables['tasks'],['billed_amount'=>number_format($amount,2,'.',''),'billing_updated_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')],['id'=>$task_id]);
        if($ok===false) wp_send_json_error(['message'=>'Could not update billing']);
        $this->log_activity('task_billed','Billed Amount: '.number_format($amount,2,'.',''),(int)$task->workspace_id,(int)$task->work_area_id,$task_id);
        wp_send_json_success(['task_id'=>$task_id,'billed_amount'=>$amount]);
    }

    public function ajax_save_task(){
        $this->verify(); global $wpdb; $id=absint($_POST['id']??0);
        $existing_task=$id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d",$id)):null;
        $old_assignees=$id?$this->task_assignee_employee_ids($id,$existing_task?(int)$existing_task->assignee_id:0):[];
        $task_type=sanitize_text_field($_POST['task_type']??'One Time'); $is_recurring=$task_type==='Recurring';
        $workspace_id=absint($_POST['workspace_id']??0); $work_area_id=absint($_POST['work_area_id']??0);
        if(!$workspace_id || !$work_area_id) wp_send_json_error(['message'=>'Workspace and Work Area are required']);
        if($id){ if(!$this->task_permission($id,'edit')) wp_send_json_error(['message'=>'Only an Owner can edit this task'],403); }
        $mapped=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['workspace_areas']} WHERE workspace_id=%d AND work_area_id=%d",$workspace_id,$work_area_id));
        if(!$mapped) wp_send_json_error(['message'=>'Selected Work Area is not attached to this Workspace']);
        if(!$id && !$this->workspace_permission($workspace_id,'add') && !$this->work_area_permission($work_area_id,'add')){
            wp_send_json_error(['message'=>'Only a Workspace Owner/Manager or this Work Area Owner can add tasks here'],403);
        }

        $assignments_posted=isset($_POST['assignments']);
        $assignment_items=$assignments_posted?$this->assignment_items_from_post('assignments'):[];
        $assignee_id=absint($_POST['assignee_id']??0);
        if($assignment_items){ foreach($assignment_items as $ai){ if(($ai['relation_type']??'')==='assignee' && !empty($ai['employee_id'])){ $assignee_id=absint($ai['employee_id']); break; } } }
        // v4.13.81: when editing an existing Task and assignments were explicitly
        // submitted, respect an empty/no-assignee selection. Do not silently restore
        // a Work Area default assignee after the user removed it.
        // v4.14.97: a Work Area Owner must never become a Task Assignee merely because
        // the task was created inside that Work Area. Only an explicit Work Area Assignee
        // may be used as the create-time default assignee. The legacy head_employee_id
        // field can contain a Work Area Owner, so it is intentionally not used here.
        if(!$assignee_id && (!$id || !$assignments_posted)) $assignee_id=absint($wpdb->get_var($wpdb->prepare("SELECT employee_id FROM {$this->tables['entity_assignments']} WHERE entity_type='work_area' AND entity_id=%d AND relation_type='assignee' AND archived_at IS NULL ORDER BY id LIMIT 1",$work_area_id)));
        // v4.18.165: every task needs at least one Assignee.
        if(!$assignee_id) wp_send_json_error(['message'=>'Select at least one Assignee'],422);
        $task_name=sanitize_text_field(wp_unslash($_POST['task_name']??''));
        if(!$task_name) wp_send_json_error(['message'=>'Task name is required'],422);
        $task_name_length=$this->task_name_length($task_name);
        if($task_name_length>220) wp_send_json_error(['message'=>'Task name cannot exceed 220 characters. Please reduce by '.($task_name_length-220).' character'.(($task_name_length-220)===1?'':'s').'.'],422);
        $data=[
            'task_name'=>$task_name,'workspace_id'=>$workspace_id,'work_area_id'=>$work_area_id,'assignee_id'=>$assignee_id?:null,
            'priority'=>$this->normalize_priority($_POST['priority']??''),'task_type'=>$is_recurring?'Recurring':'One Time','is_template'=>$is_recurring?1:0,
            'frequency'=>$is_recurring?sanitize_text_field($_POST['frequency']??'Daily'):'None','interval_value'=>$is_recurring?max(1,absint($_POST['interval_value']??1)):1,'interval_unit'=>$is_recurring?sanitize_text_field($_POST['interval_unit']??'Days'):'Days',
            'start_date'=>$is_recurring?(sanitize_text_field($_POST['start_date']??'')?:current_time('Y-m-d')):null,'end_date'=>$is_recurring?(sanitize_text_field($_POST['end_date']??'')?:null):null,
            'due_date'=>$is_recurring?null:(sanitize_text_field($_POST['due_date']??'')?:null),'due_time'=>sanitize_text_field($_POST['due_time']??'')?:null,
            'occurrence_date'=>$is_recurring?null:(sanitize_text_field($_POST['due_date']??'')?:null),'description'=>sanitize_textarea_field($_POST['description']??''),'labels'=>sanitize_text_field($_POST['labels']??''),'estimated_minutes'=>absint($_POST['estimated_minutes']??0),'is_billable'=>!empty($_POST['is_billable'])?1:0,'billable_amount'=>(!empty($_POST['is_billable']) && isset($_POST['billable_amount']) && $_POST['billable_amount']!=='')?max(0,(float)$_POST['billable_amount']):null,'notify_enabled'=>isset($_POST['notify_enabled'])?(!empty($_POST['notify_enabled'])?1:0):($existing_task?((int)($existing_task->notify_enabled??1)):1),'reminder_enabled'=>isset($_POST['reminder_enabled'])?(!empty($_POST['reminder_enabled'])?1:0):($existing_task?((int)($existing_task->reminder_enabled??0)):0),'reminder_value'=>isset($_POST['reminder_value'])?max(1,min(9999,absint($_POST['reminder_value']))):($existing_task?max(1,(int)($existing_task->reminder_value??1)):1),'reminder_unit'=>isset($_POST['reminder_unit'])?sanitize_text_field($_POST['reminder_unit']):($existing_task?sanitize_text_field($existing_task->reminder_unit??'Hours'):'Hours'),'sunday_reminder_enabled'=>isset($_POST['sunday_reminder_enabled'])?(!empty($_POST['sunday_reminder_enabled'])?1:0):($existing_task?((int)($existing_task->sunday_reminder_enabled??0)):0),'recurrence_status'=>$is_recurring?sanitize_key($_POST['recurrence_status']??'active'):'active','overdue_policy'=>$is_recurring?(sanitize_key($_POST['overdue_policy']??'until_next')==='keep'?'keep':'until_next'):'until_next','updated_at'=>current_time('mysql')
        ];
        if((int)get_option('bb_show_module_billing',0)!==1){ $data['is_billable']=0; $data['billable_amount']=null; }
        if(isset($_POST['goal_target'])){ $data['goal_target']=min(1000000,absint($_POST['goal_target'])); $data['goal_unit']=$data['goal_target']?mb_substr(sanitize_text_field(wp_unslash($_POST['goal_unit']??'')),0,40):''; }
        if(empty($data['is_billable'])){ $data['billable_amount']=null; $data['billed_amount']=null; $data['billing_updated_at']=null; }
        $posted_reminders=null;
        if(isset($_POST['reminders'])){
            $posted_reminders=$this->normalize_posted_reminders($_POST['reminders'],$data['due_time']??'');
            $data['reminder_enabled']=$posted_reminders?1:0;
            if($posted_reminders){
                $data['reminder_value']=$posted_reminders[0]['value'];
                $data['reminder_unit']=$posted_reminders[0]['unit'];
            }
        }
        // v4.14.47: allow the compact Quick Add form to configure After Due reminders at creation time.
        $after_due_posted=isset($_POST['after_due_enabled']) || isset($_POST['after_due_interval_value']) || isset($_POST['after_due_interval_unit']);
        $after_due_settings=null;
        if($after_due_posted){
            $after_due_settings=$this->normalize_overdue_reminder_settings([
                'enabled'=>!empty($_POST['after_due_enabled'])?1:0,
                'interval_value'=>$_POST['after_due_interval_value']??1,
                'interval_unit'=>$_POST['after_due_interval_unit']??'Hours',
                'active_start'=>$_POST['after_due_active_start']??'08:00',
                'active_end'=>$_POST['after_due_active_end']??'20:00',
                'all_day'=>!empty($_POST['after_due_all_day'])?1:0,
                'duration_days'=>$_POST['after_due_duration_days']??0,
                'max_reminders'=>$_POST['after_due_max_reminders']??0,
            ],true);
            if(!empty($after_due_settings['enabled']) && $after_due_settings['interval_unit']==='Minutes' && empty($data['due_time']))
                wp_send_json_error(['message'=>'Minutes after-due reminder requires Due Time']);
        }

        // Keep Due Time blank in the UI/database unless the user explicitly selects a time.
        // Reminder scheduling still uses 10:00 AM as the server-side fallback for date-only tasks.
        if(!$is_recurring && empty($data['due_date'])) $data['due_time']=null;
        if(!$is_recurring && !empty($data['due_date']) && $data['due_date'] < current_time('Y-m-d')){
            $same_existing=$existing_task && !empty($existing_task->due_date) && (string)$existing_task->due_date===(string)$data['due_date'];
            if(!$same_existing) wp_send_json_error(['message'=>'Due Date cannot be earlier than today']);
        }
        if(!in_array($data['reminder_unit'],['Minutes','Hours','Days','Weeks'],true)) $data['reminder_unit']='Hours';
        if($data['reminder_unit']==='Minutes') $data['reminder_value']=max(5,(int)$data['reminder_value']);
        if($data['reminder_enabled']){
            if(!$is_recurring && empty($data['due_date'])) wp_send_json_error(['message'=>'Due Date is required to set a reminder']);
            if($data['reminder_unit']==='Minutes' && empty($data['due_time'])) wp_send_json_error(['message'=>'Minutes reminder requires Due Time']);
        }
        // v4.16.176: sub tasks are hidden by default on task cards.
        // Users can reveal them per card with the eye button; the expanded state is intentionally not persisted.
        if(isset($_POST['checklist'])){
            $data['show_subtasks']=0;
        }

        $existing_slug=$id?(string)$wpdb->get_var($wpdb->prepare("SELECT slug FROM {$this->tables['tasks']} WHERE id=%d",$id)):'';
        $data['slug']=$existing_slug?:$this->unique_task_slug($data['task_name'],$id);
        if($is_recurring && $data['frequency']==='Custom Interval' && $data['interval_value']<1) wp_send_json_error(['message'=>'Custom interval must be at least 1']);
        if(!$is_recurring){ $data['status']=sanitize_text_field($_POST['status']??'Pending'); $data['completed_at']=null; $data['completion_result']=''; }
        else { $data['status']='Template'; $data['completed_at']=null; $data['completion_result']=''; }

        // Create-time Focus: add a newly created task to Focus immediately when requested.
        // For recurring tasks, Focus is scoped to the first/current created occurrence only,
        // rather than carrying forward to every future recurrence.
        if(!$id && !empty($_POST['focus_enabled'])){
            $data['is_focus']=1;
            $data['focus_manual_override_date']=null;
            $data['focus_auto_date']=$is_recurring ? ($data['start_date']?:current_time('Y-m-d')) : null;
        }

        // Quick Create Flash: immediately pin/highlight the newly created task.
        if(!$id && !empty($_POST['flash_enabled'])){
            $data['pinned_at']=current_time('mysql');
        }

        // New tasks should take the first manual position in their Work Area immediately.
        // Due-date/time grouping still remains the primary board sort; this controls the order
        // inside the same bucket/date/time and matches the optimistic UI position.
        if(!$id){
            $min_sort=$wpdb->get_var($wpdb->prepare("SELECT MIN(sort_order) FROM {$this->tables['tasks']} WHERE workspace_id=%d AND work_area_id=%d AND archived_at IS NULL",$workspace_id,$work_area_id));
            $data['sort_order']=$min_sort===null?0:((int)$min_sort-1);
        }

        $ok=$id?$wpdb->update($this->tables['tasks'],$data,['id'=>$id]):$wpdb->insert($this->tables['tasks'],$data);
        if($ok===false) wp_send_json_error(['message'=>'Could not save task']);
        $task_id=$id?:$wpdb->insert_id;
        // Link tasks created from CallSpace so each CallSpace row can show its task count.
        if(!$id){
            $source_callspace_id=absint($_POST['source_callspace_id']??0);
            if($source_callspace_id){
                $call_exists=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['callspace']} WHERE id=%d",$source_callspace_id));
                if($call_exists){
                    $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$this->tables['callspace_tasks']} (callspace_id,task_id,created_at) VALUES (%d,%d,%s)",$source_callspace_id,$task_id,current_time('mysql')));
                    // Keep the legacy task_id field populated with the first linked task for backward compatibility.
                    $wpdb->query($wpdb->prepare("UPDATE {$this->tables['callspace']} SET task_id=COALESCE(task_id,%d),updated_at=%s WHERE id=%d",$task_id,current_time('mysql'),$source_callspace_id));
                }
            }
        }
        if($posted_reminders!==null) $this->sync_additional_task_reminders($task_id,$posted_reminders);
        elseif(!$data['reminder_enabled']){ $wpdb->delete($this->tables['task_reminders'],['task_id'=>$task_id]); }
        if($after_due_settings!==null) $this->sync_task_overdue_reminder_settings($task_id,$after_due_settings);
        if(isset($_POST['assignments'])) $this->save_entity_assignments_internal('task',$task_id,$assignment_items);
        $saved_task=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d",$task_id));
        $new_assignees=$this->task_assignee_employee_ids($task_id,$saved_task?(int)$saved_task->assignee_id:0);
        $newly_assigned=$id?array_values(array_diff($new_assignees,$old_assignees)):$new_assignees;
        if($newly_assigned) $this->notify_task_assignment($saved_task,$newly_assigned);
        // v4.18.85: a normal task edit sends Task Updated only to the explicit
        // Task Owner(s), and only when this task's Notification toggle is ON.
        // notify_task_owners() deliberately does not inherit Workspace/Work Area
        // owners, and task_notifications_enabled() enforces notify_enabled.
        if($id && $saved_task){
            $this->notify_task_owners($saved_task,'Task updated','The task was updated.');
        }
        // Newly-added assignees still receive Task Assigned above; Task Updated
        // is never sent to assignees unless they are also explicitly Task Owners.

        if(isset($_POST['checklist'])){
            $wpdb->delete($this->tables['checklists'],['task_id'=>$task_id]); $wpdb->delete($this->tables['subtask_progress'],['task_id'=>$task_id]);
            $items=json_decode(wp_unslash($_POST['checklist']),true);
            if(is_array($items)) foreach($items as $i=>$item){
                $text=is_array($item)?sanitize_text_field($item['text']??''):sanitize_text_field($item);
                if(!$text) continue;
                $wpdb->insert($this->tables['checklists'],['task_id'=>$task_id,'item_text'=>$text,'sort_order'=>$i]);
                $sid=$wpdb->insert_id;
                if(is_array($item) && !empty($item['assignments']) && is_array($item['assignments'])) $this->save_entity_assignments_internal('subtask',$sid,$item['assignments']);
            }
        }
        $this->log_activity($id?'task_updated':'task_created',$data['task_name'],$workspace_id,$work_area_id,$task_id);
        $response=['task_id'=>$task_id];
        if(!$id && !empty($source_callspace_id)){
            $response['source_callspace_id']=(int)$source_callspace_id;
            $response['callspace_task_count']=(int)$wpdb->get_var($wpdb->prepare(
                "SELECT ((CASE WHEN c.task_id IS NOT NULL AND EXISTS(SELECT 1 FROM {$this->tables['tasks']} t0 WHERE t0.id=c.task_id) THEN 1 ELSE 0 END) +
                 (SELECT COUNT(DISTINCT ct.task_id) FROM {$this->tables['callspace_tasks']} ct INNER JOIN {$this->tables['tasks']} t1 ON t1.id=ct.task_id WHERE ct.callspace_id=c.id AND (c.task_id IS NULL OR ct.task_id<>c.task_id)))
                 FROM {$this->tables['callspace']} c WHERE c.id=%d",
                $source_callspace_id
            ));
        }
        wp_send_json_success($response);
    }


    public function ajax_duplicate_task(){
        $this->verify(); global $wpdb;
        $raw=sanitize_text_field($_POST['id']??'');
        [$template_id,$occurrence_date]=$this->parse_virtual_id($raw);
        $id=$template_id?:absint($raw);
        if(!$id) wp_send_json_error(['message'=>'Task not found'],404);
        $task=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d",$id));
        if(!$task) wp_send_json_error(['message'=>'Task not found'],404);
        if(!$this->task_permission($id,'edit') && !$this->workspace_permission($task->workspace_id,'add') && !$this->work_area_permission($task->work_area_id,'add')){
            wp_send_json_error(['message'=>'You do not have permission to copy this task'],403);
        }

        $wpdb->query('START TRANSACTION');
        $now=current_time('mysql');
        $copy=(array)$task;
        unset($copy['id']);
        $copy['slug']=$this->unique_task_slug($task->task_name,0);
        $copy['template_id']=null;
        $copy['template_key']=$task->is_template?('copy_'.wp_generate_uuid4()):'';
        $copy['status']=$task->is_template?'Template':'Pending';
        $copy['completion_result']='';
        $copy['completed_by_employee_id']=null;
        $copy['completed_at']=null;
        $copy['archived_at']=null;
        $copy['created_at']=$now;
        $copy['updated_at']=$now;
        // Keep Flash/Focus state when copying so the duplicate remains directly below an identical source.
        $copy['pinned_at']=$task->pinned_at?:null;
        $copy['is_focus']=(int)$task->is_focus;
        $copy['focus_auto_date']=null;
        $copy['focus_manual_override_date']=null;

        // Make room directly after the source task in its Work Area.
        $siblings=array_map('intval',$wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$this->tables['tasks']} WHERE workspace_id=%d AND work_area_id=%d AND archived_at IS NULL ORDER BY sort_order ASC,id ASC",
            $task->workspace_id,$task->work_area_id
        )));
        $insert_index=array_search((int)$id,$siblings,true);
        if($insert_index===false) $insert_index=count($siblings)-1;
        $copy['sort_order']=($insert_index+1)*10;

        if($wpdb->insert($this->tables['tasks'],$copy)===false){
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message'=>'Could not copy task'],500);
        }
        $new_id=(int)$wpdb->insert_id;
        foreach($this->additional_task_reminder_rules($id) as $rr){
            $wpdb->insert($this->tables['task_reminders'],['task_id'=>$new_id,'reminder_value'=>(int)$rr->reminder_value,'reminder_unit'=>$rr->reminder_unit,'sort_order'=>(int)$rr->sort_order,'created_at'=>$now,'updated_at'=>$now]);
        }
        $overdue_copy=$this->task_overdue_reminder_settings($id);
        if($overdue_copy && !empty($overdue_copy->enabled)) $this->sync_task_overdue_reminder_settings($new_id,[
            'enabled'=>1,'interval_value'=>$overdue_copy->interval_value,'interval_unit'=>$overdue_copy->interval_unit,
            'active_start'=>substr((string)$overdue_copy->active_start,0,5),'active_end'=>substr((string)$overdue_copy->active_end,0,5),
            'all_day'=>$overdue_copy->all_day,'duration_days'=>$overdue_copy->duration_days,'max_reminders'=>$overdue_copy->max_reminders
        ]);

        array_splice($siblings,$insert_index+1,0,[$new_id]);
        foreach($siblings as $i=>$sid){
            if($wpdb->update($this->tables['tasks'],['sort_order'=>$i*10],['id'=>$sid])===false){
                $wpdb->query('ROLLBACK');
                wp_send_json_error(['message'=>'Could not place copied task'],500);
            }
        }

        // Copy direct Task Owners/Assignees.
        $assignments=$wpdb->get_results($wpdb->prepare(
            "SELECT employee_id,relation_type,rights FROM {$this->tables['entity_assignments']} WHERE entity_type='task' AND entity_id=%d AND archived_at IS NULL ORDER BY id",
            $id
        ),ARRAY_A);
        foreach($assignments as $a){
            $wpdb->insert($this->tables['entity_assignments'],[
                'entity_type'=>'task','entity_id'=>$new_id,'employee_id'=>(int)$a['employee_id'],
                'relation_type'=>$a['relation_type'],'rights'=>$a['rights'],'created_at'=>$now,'updated_at'=>$now
            ]);
        }

        // Copy Sub Tasks as fresh/incomplete rows and preserve their direct assignments.
        $subs=$wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->tables['checklists']} WHERE task_id=%d ORDER BY sort_order,id",$id
        ));
        foreach($subs as $sub){
            if($wpdb->insert($this->tables['checklists'],[
                'task_id'=>$new_id,'item_text'=>$sub->item_text,'sort_order'=>(int)$sub->sort_order,'is_done'=>0
            ])===false){
                $wpdb->query('ROLLBACK');
                wp_send_json_error(['message'=>'Could not copy sub tasks'],500);
            }
            $new_sub_id=(int)$wpdb->insert_id;
            $sub_assignments=$wpdb->get_results($wpdb->prepare(
                "SELECT employee_id,relation_type,rights FROM {$this->tables['entity_assignments']} WHERE entity_type='subtask' AND entity_id=%d AND archived_at IS NULL ORDER BY id",
                $sub->id
            ),ARRAY_A);
            foreach($sub_assignments as $a){
                $wpdb->insert($this->tables['entity_assignments'],[
                    'entity_type'=>'subtask','entity_id'=>$new_sub_id,'employee_id'=>(int)$a['employee_id'],
                    'relation_type'=>$a['relation_type'],'rights'=>$a['rights'],'created_at'=>$now,'updated_at'=>$now
                ]);
            }
        }

        $wpdb->query('COMMIT');
        $this->log_activity('task_created',$task->task_name,$task->workspace_id,$task->work_area_id,$new_id);
        wp_send_json_success(['task_id'=>$new_id]);
    }

    private function completion_result_for($due_date,$due_time,$completed_at){
        if(!$due_date) return 'On Time';
        $due=strtotime($due_date.' '.($due_time?:'23:59:59')); $done=strtotime($completed_at);
        if(!$due || !$done) return 'On Time';
        if($done<$due) return 'Early';
        if($done>$due) return 'Late';
        return 'On Time';
    }

    public function ajax_archive_task(){
        $this->verify(); global $wpdb; $id=absint($_POST['id']??0); $occurrence_date=sanitize_text_field($_POST['occurrence_date']??'');
        $task=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d",$id)); if(!$task) wp_send_json_error(['message'=>'Task not found']);
        if(!$this->task_permission($id,'edit')) wp_send_json_error(['message'=>'Only an Owner can archive this task'],403);
        // A recurring occurrence can be archived independently without stopping/archiving the recurring series.
        if($task->is_template && $occurrence_date){
            $h=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['history']} WHERE task_id=%d AND occurrence_date=%s LIMIT 1",$id,$occurrence_date));
            if(!$h) wp_send_json_error(['message'=>'Recurring occurrence not found']);
            $wpdb->update($this->tables['history'],['status'=>'Archived','updated_at'=>current_time('mysql')],['id'=>$h->id]);
            $this->log_activity('occurrence_archived',$task->task_name.' — '.$occurrence_date,$task->workspace_id,$task->work_area_id,$id,$occurrence_date);
            wp_send_json_success(['scope'=>'occurrence']);
        }
        $wpdb->update($this->tables['tasks'],['archived_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')],['id'=>$id]);
        $this->log_activity('task_archived',$task->task_name,$task->workspace_id,$task->work_area_id,$id);
        wp_send_json_success(['scope'=>'task']);
    }

    public function ajax_unarchive_task(){
        $this->verify(); global $wpdb; $id=absint($_POST['id']??0); $occurrence_date=sanitize_text_field($_POST['occurrence_date']??'');
        $task=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d",$id)); if(!$task) wp_send_json_error(['message'=>'Task not found']);
        if(!$this->task_permission($id,'edit')) wp_send_json_error(['message'=>'Only an Owner can unarchive this task'],403);
        if($task->is_template && $occurrence_date){
            $h=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['history']} WHERE task_id=%d AND occurrence_date=%s AND status='Archived' LIMIT 1",$id,$occurrence_date));
            if(!$h) wp_send_json_error(['message'=>'Archived occurrence not found']);
            $restore=$h->completed_at?'Completed':((strtotime($h->due_date)<strtotime(current_time('Y-m-d')))?'Overdue':'Pending');
            $wpdb->update($this->tables['history'],['status'=>$restore,'updated_at'=>current_time('mysql')],['id'=>$h->id]);
            $this->log_activity('occurrence_unarchived',$task->task_name.' — '.$occurrence_date,$task->workspace_id,$task->work_area_id,$id,$occurrence_date);
            wp_send_json_success(['scope'=>'occurrence','status'=>$restore]);
        }
        $wpdb->update($this->tables['tasks'],['archived_at'=>null,'updated_at'=>current_time('mysql')],['id'=>$id]);
        $this->log_activity('task_unarchived',$task->task_name,$task->workspace_id,$task->work_area_id,$id);
        wp_send_json_success(['scope'=>'task']);
    }

    private function delete_recurring_occurrence($task,$occurrence_date,$delete_activity=false){
        global $wpdb;
        $id=(int)$task->id;
        if(!$occurrence_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$occurrence_date)) return false;

        // The exception is essential: without it, deleting the displayed row would simply let the
        // recurrence engine recreate the same occurrence on the next refresh.
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$this->tables['recurrence_exceptions']} (task_id,occurrence_date,exception_type,note,created_by,created_at)
             VALUES (%d,%s,'Deleted','Occurrence deleted by user',%d,%s)
             ON DUPLICATE KEY UPDATE exception_type='Deleted',note='Occurrence deleted by user',created_by=VALUES(created_by),created_at=VALUES(created_at)",
            $id,$occurrence_date,get_current_user_id(),current_time('mysql')
        ));
        $wpdb->delete($this->tables['history'],['task_id'=>$id,'occurrence_date'=>$occurrence_date]);
        $wpdb->delete($this->tables['subtask_progress'],['task_id'=>$id,'occurrence_key'=>$occurrence_date]);
        $wpdb->delete($this->tables['task_remarks'],['task_id'=>$id,'occurrence_key'=>$occurrence_date]);

        $attachments=$wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->tables['attachments']} WHERE task_id=%d AND occurrence_date=%s",$id,$occurrence_date
        ));
        foreach($attachments as $a) wp_delete_attachment($a->attachment_id,true);
        $wpdb->delete($this->tables['attachments'],['task_id'=>$id,'occurrence_date'=>$occurrence_date]);

        if($delete_activity) $wpdb->delete($this->tables['activity'],['task_id'=>$id,'occurrence_date'=>$occurrence_date]);
        else $this->log_activity('occurrence_deleted',$task->task_name.' — '.$occurrence_date,$task->workspace_id,$task->work_area_id,$id,$occurrence_date);
        return true;
    }

    public function ajax_delete_task_history(){
        $this->verify(); global $wpdb;
        $raw=sanitize_text_field($_POST['id']??'');
        [$virtual_template_id,$virtual_occurrence]=$this->parse_virtual_id($raw);
        $id=$virtual_template_id?:absint($raw);
        $occurrence_date=sanitize_text_field($_POST['occurrence_date']??'') ?: $virtual_occurrence;
        if(!$id) wp_send_json_error(['message'=>'Task not found']);
        $task=$wpdb->get_row($wpdb->prepare("SELECT id,task_name,workspace_id,work_area_id FROM {$this->tables['tasks']} WHERE id=%d",$id));
        if(!$task) wp_send_json_error(['message'=>'Task not found']);
        if(!$this->task_permission($id,'edit')) wp_send_json_error(['message'=>'Only an Owner can delete task history'],403);

        if($occurrence_date!==''){
            $wpdb->query($wpdb->prepare("DELETE FROM {$this->tables['activity']} WHERE task_id=%d AND occurrence_date=%s",$id,$occurrence_date));
        }else{
            $wpdb->delete($this->tables['activity'],['task_id'=>$id]);
        }
        wp_send_json_success(['message'=>'Task activity deleted']);
    }

    public function ajax_delete_task(){
        $this->verify(); global $wpdb;
        $raw=sanitize_text_field($_POST['id']??'');
        [$virtual_template_id,$virtual_occurrence]=$this->parse_virtual_id($raw);
        $id=$virtual_template_id?:absint($raw);
        $mode=sanitize_key($_POST['mode']??'retain_activity');
        $scope=sanitize_key($_POST['scope']??'task');
        $occurrence_date=sanitize_text_field($_POST['occurrence_date']??'') ?: $virtual_occurrence;
        $task=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d",$id)); if(!$task) wp_send_json_error(['message'=>'Task not found']);
        if(!$this->task_permission($id,'edit')) wp_send_json_error(['message'=>'Only an Owner can delete this task'],403);

        if($task->is_template && $occurrence_date && $scope!=='series'){
            $ok=$this->delete_recurring_occurrence($task,$occurrence_date,$mode==='all');
            if(!$ok) wp_send_json_error(['message'=>'Recurring occurrence could not be deleted']);
            wp_send_json_success(['scope'=>'occurrence','occurrence_date'=>$occurrence_date]);
        }

        if($mode!=='all') $this->log_activity('task_deleted',$task->task_name,$task->workspace_id,$task->work_area_id,$id);
        $wpdb->delete($this->tables['history'],['task_id'=>$id]);
        $wpdb->delete($this->tables['recurrence_exceptions'],['task_id'=>$id]);
        $wpdb->delete($this->tables['score_archive'],['task_id'=>$id]);
        $attachments=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tables['attachments']} WHERE task_id=%d",$id));
        foreach($attachments as $a) wp_delete_attachment($a->attachment_id,true);
        $wpdb->delete($this->tables['attachments'],['task_id'=>$id]);
        $subs=$wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->tables['checklists']} WHERE task_id=%d",$id)); foreach($subs as $sid) $wpdb->delete($this->tables['entity_assignments'],['entity_type'=>'subtask','entity_id'=>$sid]);
        $wpdb->delete($this->tables['checklists'],['task_id'=>$id]); $wpdb->delete($this->tables['subtask_progress'],['task_id'=>$id]);
        $wpdb->delete($this->tables['entity_assignments'],['entity_type'=>'task','entity_id'=>$id]); $wpdb->delete($this->tables['task_remarks'],['task_id'=>$id]); $wpdb->delete($this->tables['task_reminders'],['task_id'=>$id]); $wpdb->delete($this->tables['task_overdue_reminders'],['task_id'=>$id]); $wpdb->delete($this->tables['reminder_delivery'],['task_id'=>$id]);
        if($mode==='all') $wpdb->delete($this->tables['activity'],['task_id'=>$id]);
        $wpdb->delete($this->tables['tasks'],['id'=>$id]); wp_send_json_success(['scope'=>$task->is_template?'series':'task']);
    }

    private function parse_virtual_id($id){
        if(preg_match('/^r_(\d+)_(\d{4}-\d{2}-\d{2})$/',(string)$id,$m)) return [(int)$m[1],$m[2]];
        return [0,''];
    }

    private function completion_actor_employee_id($workspace_id){
        if($e=$this->current_employee()) return (int)$e->id;
        return 0;
    }

    public function ajax_toggle_pin_task(){
        $this->verify(); global $wpdb;
        $raw=sanitize_text_field($_POST['id']??'');
        [$template_id,$occurrence_date]=$this->parse_virtual_id($raw);
        $id=$template_id?:absint($raw);
        if(!$id) wp_send_json_error(['message'=>'Task not found']);
        $task=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d",$id));
        if(!$task) wp_send_json_error(['message'=>'Task not found']);
        if(!$this->workspace_permission($task->workspace_id,'edit')) wp_send_json_error(['message'=>'You do not have permission to pin tasks in this Workspace'],403);
        $pin=!empty($_POST['pin']);
        $wpdb->update($this->tables['tasks'],['pinned_at'=>$pin?current_time('mysql'):null,'updated_at'=>current_time('mysql')],['id'=>$id]);
        $this->log_activity($pin?'task_pinned':'task_unpinned',$task->task_name,$task->workspace_id,$task->work_area_id,$task->id,$occurrence_date?:null);
        wp_send_json_success(['pinned_at'=>$pin?current_time('mysql'):null]);
    }

    // v4.18.173: Universal search — Work Areas, Tasks (incl. recurring) and Folders across every Workspace the user can access.
    public function ajax_universal_search(){
        $this->verify(); global $wpdb;
        $q=trim(sanitize_text_field(wp_unslash($_POST['q']??'')));
        if(mb_strlen($q)<2) wp_send_json_success(['q'=>$q,'areas'=>[],'tasks'=>[],'folders'=>[]]);
        $like='%'.$wpdb->esc_like($q).'%';
        $allowed=$this->allowed_workspace_ids();
        $ws_ok=function($wid) use($allowed){ return $allowed===null || in_array((int)$wid,$allowed,true); };
        // Work Areas
        $areas=[];
        $rows=$wpdb->get_results($wpdb->prepare("SELECT a.id,a.name,a.color,wa.workspace_id,w.name workspace_name,w.slug workspace_slug FROM {$this->tables['work_areas']} a JOIN {$this->tables['workspace_areas']} wa ON wa.work_area_id=a.id JOIN {$this->tables['workspaces']} w ON w.id=wa.workspace_id WHERE a.name LIKE %s AND COALESCE(a.status,'active') NOT IN ('trash','archived') AND COALESCE(w.status,'active')='active' ORDER BY a.name LIMIT 60",$like));
        foreach($rows as $r){ if(!$ws_ok($r->workspace_id)) continue; $areas[]=['id'=>(int)$r->id,'name'=>(string)$r->name,'color'=>(string)$r->color,'workspace_id'=>(int)$r->workspace_id,'workspace'=>(string)$r->workspace_name,'workspace_slug'=>(string)$r->workspace_slug]; if(count($areas)>=20) break; }
        // Tasks: one-time tasks and recurring task templates (name, description, labels)
        $tasks=[];
        $rows=$wpdb->get_results($wpdb->prepare("SELECT t.id,t.task_name,t.slug,t.status,t.task_type,t.frequency,t.is_template,t.due_date,t.due_time,t.workspace_id,t.work_area_id,t.assignee_id,COALESCE(t.recurrence_status,'active') recurrence_status,w.name workspace_name,a.name work_area_name,e.name assignee_name FROM {$this->tables['tasks']} t LEFT JOIN {$this->tables['workspaces']} w ON w.id=t.workspace_id LEFT JOIN {$this->tables['work_areas']} a ON a.id=t.work_area_id LEFT JOIN {$this->tables['employees']} e ON e.id=t.assignee_id WHERE t.archived_at IS NULL AND t.template_id IS NULL AND (t.task_name LIKE %s OR t.description LIKE %s OR t.labels LIKE %s) AND COALESCE(w.status,'active')='active' AND (a.status IS NULL OR a.status NOT IN ('trash','archived')) ORDER BY (t.status='Completed' AND t.is_template=0), (t.task_name LIKE %s) DESC, t.updated_at DESC LIMIT 150",$like,$like,$like,$like));
        foreach($rows as $r){
            if(!$ws_ok($r->workspace_id)) continue;
            if(!$this->task_permission((int)$r->id,'view')) continue;
            $recurring=(int)$r->is_template===1;
            $tasks[]=['id'=>(int)$r->id,'name'=>(string)$r->task_name,'slug'=>(string)$r->slug,'status'=>$recurring?($r->recurrence_status==='paused'?'Paused':'Recurring'):(string)($r->status?:'Pending'),'frequency'=>$recurring?(string)($r->frequency?:'Daily'):'','due_date'=>(string)($r->due_date?:''),'workspace_id'=>(int)$r->workspace_id,'workspace'=>(string)($r->workspace_name?:''),'work_area'=>(string)($r->work_area_name?:''),'assignee'=>(string)($r->assignee_name?:'')];
            if(count($tasks)>=40) break;
        }
        // Folders (any folder that holds at least one accessible Workspace, or all for Owner/Manager)
        $folders=[];
        $rows=$wpdb->get_results($wpdb->prepare("SELECT f.id,f.name,f.color FROM {$this->tables['folders']} f WHERE f.name LIKE %s ORDER BY f.name LIMIT 30",$like));
        foreach($rows as $r){
            if($allowed!==null){ $wids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT workspace_id FROM {$this->tables['folder_workspaces']} WHERE folder_id=%d",(int)$r->id))); if(!array_intersect($wids,$allowed)) continue; }
            $folders[]=['id'=>(int)$r->id,'name'=>(string)$r->name,'color'=>(string)$r->color];
        }
        wp_send_json_success(['q'=>$q,'areas'=>$areas,'tasks'=>$tasks,'folders'=>$folders]);
    }

    // ===== v4.18.168: Goals (target count) on tasks =====
    private function goal_total($task_id,$occ){
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount),0) FROM {$this->tables['goal_progress']} WHERE task_id=%d AND occurrence_key=%s",(int)$task_id,(string)$occ));
    }
    // Goal for one day/occurrence: the Goal saved with that day's latest progress entry; if none yet, the task's current Goal.
    private function goal_day_target($task,$occ){
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare("SELECT goal_target,goal_unit FROM {$this->tables['goal_progress']} WHERE task_id=%d AND occurrence_key=%s AND goal_target>0 ORDER BY id DESC LIMIT 1",(int)$task->id,(string)$occ));
        if($row) return [(int)$row->goal_target,(string)$row->goal_unit];
        return [(int)($task->goal_target??0),(string)($task->goal_unit??'')];
    }
    private function goal_allows_completion($task,$occ){
        if((int)($task->goal_target??0)<=0) return true;
        if($this->is_privileged_user()) return true;
        [$target]=$this->goal_day_target($task,$occ);
        return $target<=0 || $this->goal_total((int)$task->id,$occ)>=$target;
    }
    // Resolve a task id coming from the UI (one-time id or r_<template>_<date>) to [task row, occurrence key, is_recurring].
    private function goal_resolve_task($raw){
        global $wpdb;
        [$template_id,$occurrence_date]=$this->parse_virtual_id($raw);
        if($template_id){
            $t=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d AND is_template=1",$template_id));
            return [$t,$occurrence_date,true];
        }
        $id=absint($raw);
        $t=$id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d",$id)):null;
        if($t && (int)$t->is_template===1) return [$t,current_time('Y-m-d'),true];
        return [$t,'one',false];
    }
    private function goal_is_completed($task,$occ,$recurring){
        global $wpdb;
        if(!$recurring) return (string)$task->status==='Completed';
        return (string)$wpdb->get_var($wpdb->prepare("SELECT status FROM {$this->tables['history']} WHERE task_id=%d AND occurrence_date=%s",(int)$task->id,$occ))==='Completed';
    }
    // Complete the task / occurrence when the Goal is reached (same records as a normal completion).
    private function goal_auto_complete($task,$occ,$recurring){
        global $wpdb;
        if($this->goal_is_completed($task,$occ,$recurring)) return false;
        $total_sub=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['checklists']} WHERE task_id=%d",(int)$task->id));
        if($total_sub){
            $done_sub=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['subtask_progress']} WHERE task_id=%d AND occurrence_key=%s AND is_done=1",(int)$task->id,$occ));
            if($done_sub<$total_sub) return false;
        }
        $at=current_time('mysql');
        if($recurring){
            $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['history']} WHERE task_id=%d AND occurrence_date=%s",(int)$task->id,$occ));
            $result=$this->completion_result_for($occ,$task->due_time,$at); $points=$result==='Late'?60:100;
            $data=['task_id'=>(int)$task->id,'occurrence_date'=>$occ,'due_date'=>$occ,'due_time'=>$task->due_time,'assignee_id'=>$task->assignee_id,'workspace_id'=>$task->workspace_id,'work_area_id'=>$task->work_area_id,'status'=>'Completed','stage_name'=>'','result'=>$result,'completed_at'=>$at,'score_points'=>$points,'updated_at'=>$at];
            if($existing) $wpdb->update($this->tables['history'],$data,['id'=>$existing->id]); else $wpdb->insert($this->tables['history'],$data);
            $wpdb->update($this->tables['tasks'],['pinned_at'=>null,'updated_at'=>$at],['id'=>(int)$task->id]);
        } else {
            $wpdb->update($this->tables['tasks'],['status'=>'Completed','stage_name'=>'','completed_by_employee_id'=>$this->completion_actor_employee_id($task->workspace_id),'completed_at'=>$at,'completion_result'=>$this->completion_result_for($task->due_date,$task->due_time,$at),'pinned_at'=>null,'updated_at'=>$at],['id'=>(int)$task->id]);
        }
        [$dt,$du]=$this->goal_day_target($task,$occ);
        $this->log_activity('task_completed',$task->task_name.' — Goal reached ('.$dt.' '.($du?:'').')',$task->workspace_id,$task->work_area_id,(int)$task->id,$recurring?$occ:$task->occurrence_date);
        $this->notify_task_owners($task,'Task completed','Goal reached: '.$dt.' '.($du?:''));
        return true;
    }
    private function goal_payload($task,$occ,$recurring){
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT g.*,e.name employee_name FROM {$this->tables['goal_progress']} g LEFT JOIN {$this->tables['employees']} e ON e.id=g.employee_id WHERE g.task_id=%d AND g.occurrence_key=%s ORDER BY g.created_at DESC,g.id DESC",(int)$task->id,$occ));
        $me=$this->current_employee(); $priv=$this->is_privileged_user(); $entries=[]; $total=0;
        foreach($rows as $r){
            $total+=(int)$r->amount;
            $entries[]=['id'=>(int)$r->id,'amount'=>(int)$r->amount,'remark'=>(string)($r->remark??''),'employee_id'=>(int)$r->employee_id,'employee_name'=>(string)($r->employee_name?:'User'),'created_at'=>$r->created_at?$this->activity_time_ist($r->created_at):'','day'=>$r->created_at?substr($this->activity_time_ist($r->created_at),0,10):'','can_edit'=>($priv || ($me && (int)$me->id===(int)$r->employee_id))?1:0];
        }
        [$dt,$du]=$this->goal_day_target($task,$occ);
        return ['task_id'=>(int)$task->id,'occurrence'=>$occ,'recurring'=>$recurring?1:0,'target'=>$dt,'unit'=>$du,'total'=>$total,'completed'=>$this->goal_is_completed($task,$occ,$recurring)?1:0,'entries'=>$entries,'can_complete_anyway'=>$priv?1:0];
    }
    public function ajax_goal_progress_list(){
        $this->verify(); $this->ensure_goal_schema(true);
        [$task,$occ,$recurring]=$this->goal_resolve_task(sanitize_text_field($_POST['task_id']??''));
        if(!$task) wp_send_json_error(['message'=>'Task not found'],404);
        if(!$this->task_permission((int)$task->id,'view')) wp_send_json_error(['message'=>'You do not have access to this task'],403);
        wp_send_json_success($this->goal_payload($task,$occ,$recurring));
    }
    public function ajax_goal_progress_add(){
        $this->verify(); global $wpdb;
        [$task,$occ,$recurring]=$this->goal_resolve_task(sanitize_text_field($_POST['task_id']??''));
        if(!$task) wp_send_json_error(['message'=>'Task not found'],404);
        if((int)$task->goal_target<=0) wp_send_json_error(['message'=>'This task has no Goal'],422);
        if(!$this->task_permission((int)$task->id,'complete')) wp_send_json_error(['message'=>'You do not have permission to update this task'],403);
        if($recurring && $occ>current_time('Y-m-d')) wp_send_json_error(['message'=>'Progress can only be updated for today or earlier'],422);
        $amount=(int)($_POST['amount']??0);
        if($amount<1 || $amount>1000000) wp_send_json_error(['message'=>'Enter a number of 1 or more'],422);
        $remark=trim(sanitize_textarea_field(wp_unslash($_POST['remark']??'')));
        // v4.18.171: quick progress taps never need a remark (the Work Area remark rule applies to status changes / completion).
        $this->ensure_goal_schema(true);
        $me=$this->current_employee(); $at=current_time('mysql');
        [$dt,$du]=($recurring && $occ<current_time('Y-m-d'))?$this->goal_day_target($task,$occ):[(int)$task->goal_target,(string)$task->goal_unit];
        $wpdb->insert($this->tables['goal_progress'],['task_id'=>(int)$task->id,'occurrence_key'=>$occ,'employee_id'=>$me?(int)$me->id:null,'amount'=>$amount,'goal_target'=>$dt,'goal_unit'=>$du,'remark'=>$remark,'created_at'=>$at,'updated_at'=>$at]);
        if(!$wpdb->insert_id) wp_send_json_error(['message'=>'Could not save progress'.($wpdb->last_error?' — '.$wpdb->last_error:'')],500);
        $total=$this->goal_total((int)$task->id,$occ);
        $this->log_activity('goal_progress','+'.$amount.' '.($du?:'').' — '.$task->task_name.' ('.$total.' / '.$dt.')'.($remark?' — '.$remark:''),$task->workspace_id,$task->work_area_id,(int)$task->id,$recurring?$occ:null);
        $auto=false;
        if($dt>0 && $total>=$dt) $auto=$this->goal_auto_complete($task,$occ,$recurring);
        $payload=$this->goal_payload($task,$occ,$recurring); $payload['auto_completed']=$auto?1:0;
        wp_send_json_success($payload);
    }
    private function goal_entry_for_edit($id){
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['goal_progress']} WHERE id=%d",absint($id)));
        if(!$row) wp_send_json_error(['message'=>'Progress entry not found'],404);
        $me=$this->current_employee();
        if(!$this->is_privileged_user() && !($me && (int)$me->id===(int)$row->employee_id)) wp_send_json_error(['message'=>'You can only change your own progress entries'],403);
        $task=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d",(int)$row->task_id));
        if(!$task) wp_send_json_error(['message'=>'Task not found'],404);
        return [$row,$task,$row->occurrence_key!=='one'];
    }
    public function ajax_goal_progress_update(){
        $this->verify(); global $wpdb;
        [$row,$task,$recurring]=$this->goal_entry_for_edit($_POST['id']??0);
        $amount=(int)($_POST['amount']??0);
        if($amount<1 || $amount>1000000) wp_send_json_error(['message'=>'Enter a number of 1 or more'],422);
        $remark=trim(sanitize_textarea_field(wp_unslash($_POST['remark']??'')));
        $wpdb->update($this->tables['goal_progress'],['amount'=>$amount,'remark'=>$remark,'updated_at'=>current_time('mysql')],['id'=>(int)$row->id]);
        $total=$this->goal_total((int)$task->id,$row->occurrence_key);
        $this->log_activity('goal_progress_edited',$task->task_name.': '.(int)$row->amount.' → '.$amount.' ('.$total.' / '.(int)$task->goal_target.')',$task->workspace_id,$task->work_area_id,(int)$task->id,$recurring?$row->occurrence_key:null);
        [$dt]=$this->goal_day_target($task,$row->occurrence_key);
        $auto=false; if($dt>0 && $total>=$dt) $auto=$this->goal_auto_complete($task,$row->occurrence_key,$recurring);
        $payload=$this->goal_payload($task,$row->occurrence_key,$recurring); $payload['auto_completed']=$auto?1:0;
        wp_send_json_success($payload);
    }
    public function ajax_goal_progress_delete(){
        $this->verify(); global $wpdb;
        [$row,$task,$recurring]=$this->goal_entry_for_edit($_POST['id']??0);
        $wpdb->delete($this->tables['goal_progress'],['id'=>(int)$row->id]);
        $total=$this->goal_total((int)$task->id,$row->occurrence_key);
        $this->log_activity('goal_progress_deleted',$task->task_name.': removed '.(int)$row->amount.' ('.$total.' / '.(int)$task->goal_target.')',$task->workspace_id,$task->work_area_id,(int)$task->id,$recurring?$row->occurrence_key:null);
        wp_send_json_success($this->goal_payload($task,$row->occurrence_key,$recurring));
    }

    public function ajax_toggle_task(){
        $this->verify(); global $wpdb; $raw=sanitize_text_field($_POST['id']??''); $done=!empty($_POST['done']);
        $remark=sanitize_textarea_field($_POST['remark']??'');
        [$template_id,$occurrence_date]=$this->parse_virtual_id($raw);
        if($template_id){
            $tpl=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d AND is_template=1",$template_id));
            if(!$tpl) wp_send_json_error(['message'=>'Recurring task not found']);
            if(!$this->task_permission($template_id,'complete')) wp_send_json_error(['message'=>'You do not have permission to complete this task'],403);
            if(!$done && !$this->can_reopen_task()) wp_send_json_error(['message'=>'Only Administrator or Manager can reopen a completed task'],403);
            if($done && !$this->goal_allows_completion($tpl,$occurrence_date)) wp_send_json_error(['message'=>'Reach the Goal first ('.$this->goal_total($template_id,$occurrence_date).' / '.(int)$tpl->goal_target.' '.($tpl->goal_unit?:'').'). Use Update Progress.','goal_required'=>1],422);
            if($done && $this->work_area_update_remarks_required((int)$tpl->work_area_id) && trim($remark)===''){
                wp_send_json_error(['message'=>'Remarks are required for task updates in this Work Area.','remark_required'=>1],422);
            }
            if($done){
                $total=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['checklists']} WHERE task_id=%d",$template_id));
                if($total){
                    $completed=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['subtask_progress']} WHERE task_id=%d AND occurrence_key=%s AND is_done=1",$template_id,$occurrence_date));
                    if($completed<$total) wp_send_json_error(['message'=>'Complete all sub tasks first','subtask_required'=>1,'done'=>$completed,'total'=>$total],422);
                }
            }
            $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['history']} WHERE task_id=%d AND occurrence_date=%s",$template_id,$occurrence_date));
            // Make completion/reopen idempotent so a repeated AJAX request cannot create duplicate Activity Log rows.
            if($done && $existing && (string)$existing->status==='Completed') wp_send_json_success(['unchanged'=>1]);
            if(!$done && (!$existing || (string)$existing->status!=='Completed')) wp_send_json_success(['unchanged'=>1]);
            if($done){
                $at=current_time('mysql'); $result=$this->completion_result_for($occurrence_date,$tpl->due_time,$at); $points=$result==='Late'?60:100;
                $data=['task_id'=>$template_id,'occurrence_date'=>$occurrence_date,'due_date'=>$occurrence_date,'due_time'=>$tpl->due_time,
                    'assignee_id'=>$tpl->assignee_id,'workspace_id'=>$tpl->workspace_id,'work_area_id'=>$tpl->work_area_id,'status'=>'Completed','stage_name'=>'','result'=>$result,
                    'completed_at'=>$at,'score_points'=>$points,'updated_at'=>$at];
                if($existing) $wpdb->update($this->tables['history'],$data,['id'=>$existing->id]); else $wpdb->insert($this->tables['history'],$data);
                // v4.12.39: completed tasks/occurrences must no longer remain Flash-highlighted.
                $wpdb->update($this->tables['tasks'],['pinned_at'=>null,'updated_at'=>$at],['id'=>$template_id]);
            } else {
                $wpdb->delete($this->tables['subtask_progress'],['task_id'=>$template_id,'occurrence_key'=>$occurrence_date]);
                $due=strtotime($occurrence_date.' '.($tpl->due_time?:'23:59:59'));
                if($due && $due<current_time('timestamp')){
                    $data=['task_id'=>$template_id,'occurrence_date'=>$occurrence_date,'due_date'=>$occurrence_date,'due_time'=>$tpl->due_time,
                        'assignee_id'=>$tpl->assignee_id,'workspace_id'=>$tpl->workspace_id,'work_area_id'=>$tpl->work_area_id,'status'=>'Overdue','stage_name'=>'','result'=>'Missed',
                        'completed_at'=>null,'score_points'=>0,'updated_at'=>current_time('mysql')];
                    if($existing) $wpdb->update($this->tables['history'],$data,['id'=>$existing->id]); else $wpdb->insert($this->tables['history'],$data);
                } elseif($existing) $wpdb->delete($this->tables['history'],['id'=>$existing->id]);
            }
            if(trim($remark)!=='') $this->save_task_workflow_remark($template_id,$occurrence_date,$remark);
            $this->log_activity($done?'task_completed':'task_reopened',$tpl->task_name.($remark?' — '.$remark:''),$tpl->workspace_id,$tpl->work_area_id,$tpl->id,$occurrence_date);
            $this->notify_task_owners($tpl,$done?'Task completed':'Task reopened',$remark?:($done?'The recurring task occurrence was completed.':'The recurring task occurrence was reopened.'));
            if($done) $this->notify_work_area_head($tpl->work_area_id,'completed','Task completed',$tpl->task_name.' was completed','done_rec_'.$tpl->id.'_'.$occurrence_date,$tpl->workspace_id,$tpl->id,$occurrence_date,$tpl->assignee_id);
            wp_send_json_success();
        }

        $id=absint($raw);
        $row=$wpdb->get_row($wpdb->prepare("SELECT due_date,due_time FROM {$this->tables['tasks']} WHERE id=%d AND is_template=0 AND template_id IS NULL",$id));
        if(!$row) wp_send_json_error(['message'=>'Task not found']);
        $full=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d",$id));
        // Ignore repeated state submissions (for example a double event/request) instead of logging the same completion twice.
        if($done && (string)$full->status==='Completed') wp_send_json_success(['unchanged'=>1]);
        if(!$done && (string)$full->status!=='Completed') wp_send_json_success(['unchanged'=>1]);
        if(!$this->task_permission($id,'complete')) wp_send_json_error(['message'=>'You do not have permission to complete this task'],403);
        if(!$done && !$this->can_reopen_task()) wp_send_json_error(['message'=>'Only Administrator or Manager can reopen a completed task'],403);
        if($done && !$this->goal_allows_completion($full,'one')) wp_send_json_error(['message'=>'Reach the Goal first ('.$this->goal_total($id,'one').' / '.(int)$full->goal_target.' '.($full->goal_unit?:'').'). Use Update Progress.','goal_required'=>1],422);
        if($done && $this->work_area_update_remarks_required((int)$full->work_area_id) && trim($remark)===''){
            wp_send_json_error(['message'=>'Remarks are required for task updates in this Work Area.','remark_required'=>1],422);
        }
        if($done){
            $total=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['checklists']} WHERE task_id=%d",$id));
            if($total){
                $completed=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['subtask_progress']} WHERE task_id=%d AND occurrence_key='one' AND is_done=1",$id));
                if($completed<$total) wp_send_json_error(['message'=>'Complete all sub tasks first','subtask_required'=>1,'done'=>$completed,'total'=>$total],422);
            }
            $at=current_time('mysql');
            $wpdb->update($this->tables['tasks'],['status'=>'Completed','stage_name'=>'','completed_by_employee_id'=>$this->completion_actor_employee_id($full->workspace_id),'completed_at'=>$at,'completion_result'=>$this->completion_result_for($row->due_date,$row->due_time,$at),'pinned_at'=>null,'updated_at'=>$at],['id'=>$id]);
        } else {
            $wpdb->delete($this->tables['subtask_progress'],['task_id'=>$id,'occurrence_key'=>'one']);
            $due=$row->due_date?strtotime($row->due_date.' '.($row->due_time?:'23:59:59')):false;
            $status=($due && $due<current_time('timestamp'))?'Overdue':'Pending';
            $wpdb->update($this->tables['tasks'],['status'=>$status,'stage_name'=>'','completed_by_employee_id'=>null,'completed_at'=>null,'completion_result'=>'','updated_at'=>current_time('mysql')],['id'=>$id]);
        }
        if(trim($remark)!=='') $this->save_task_workflow_remark($id,'one',$remark);
        $this->log_activity($done?'task_completed':'task_reopened',$full->task_name.($remark?' — '.$remark:''),$full->workspace_id,$full->work_area_id,$id,$full->occurrence_date);
        $this->notify_task_owners($full,$done?'Task completed':'Task reopened',$remark?:($done?'The task was completed.':'The task was reopened.'));
        if($done) $this->notify_work_area_head($full->work_area_id,'completed','Task completed',$full->task_name.' was completed','done_one_'.$id,$full->workspace_id,$id,$full->due_date,$full->assignee_id);
        wp_send_json_success();
    }

    public function ajax_toggle_subtask(){
        $this->verify(); global $wpdb;
        $raw=sanitize_text_field($_POST['task_id']??'');
        $subtask_id=absint($_POST['subtask_id']??0);
        $done=!empty($_POST['done']);
        [$template_id,$occurrence_date]=$this->parse_virtual_id($raw);
        $task_id=$template_id ?: absint($raw);
        $occurrence_key=$template_id ? $occurrence_date : 'one';
        if(!$task_id || !$subtask_id || !$occurrence_key) wp_send_json_error(['message'=>'Invalid sub task'],422);

        $task=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d",$task_id));
        if(!$task) wp_send_json_error(['message'=>'Task not found'],404);
        $sub=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['checklists']} WHERE id=%d AND task_id=%d",$subtask_id,$task_id));
        if(!$sub) wp_send_json_error(['message'=>'Sub task not found'],404);

        // Task owners/assignees may complete sub tasks. Also allow a user who is
        // directly assigned as Owner/Assignee on this specific sub task.
        $can_complete=$this->task_permission($task_id,'complete');
        if(!$can_complete && !$this->is_privileged_user()){
            $emp=$this->current_employee();
            if($emp){
                $rights=$this->employee_entity_rights((int)$emp->id,'subtask',$subtask_id);
                $can_complete=$rights['rank']>=1 && (in_array('assignee',$rights['relations'],true)||in_array('owner',$rights['relations'],true));
            }
        }
        if(!$can_complete) wp_send_json_error(['message'=>'You do not have permission to complete this sub task'],403);

        // Use the composite unique key directly. This avoids stale-row/race issues
        // that could make the checkbox change in the UI but fail while saving.
        if($done){
            $employee_id=$this->completion_actor_employee_id($task->workspace_id);
            $now=current_time('mysql');
            $sql=$wpdb->prepare(
                "INSERT INTO {$this->tables['subtask_progress']} (task_id,subtask_id,occurrence_key,is_done,completed_by_employee_id,completed_at,updated_at) VALUES (%d,%d,%s,1,%d,%s,%s) ON DUPLICATE KEY UPDATE is_done=1,completed_by_employee_id=VALUES(completed_by_employee_id),completed_at=VALUES(completed_at),updated_at=VALUES(updated_at)",
                $task_id,$subtask_id,$occurrence_key,$employee_id,$now,$now
            );
            $saved=$wpdb->query($sql);
            if($saved===false) wp_send_json_error(['message'=>'Could not complete sub task. Please try again.'],500);
        }else{
            $saved=$wpdb->delete($this->tables['subtask_progress'],['task_id'=>$task_id,'subtask_id'=>$subtask_id,'occurrence_key'=>$occurrence_key]);
            if($saved===false) wp_send_json_error(['message'=>'Could not reopen sub task. Please try again.'],500);
        }

        $total=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['checklists']} WHERE task_id=%d",$task_id));
        $completed=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['subtask_progress']} WHERE task_id=%d AND occurrence_key=%s AND is_done=1",$task_id,$occurrence_key));

        if(!$done){
            if($template_id){
                $wpdb->update($this->tables['history'],['status'=>'Pending','result'=>'','completed_at'=>null,'score_points'=>0,'updated_at'=>current_time('mysql')],['task_id'=>$task_id,'occurrence_date'=>$occurrence_date]);
            }else{
                $due=$task->due_date?strtotime($task->due_date.' '.($task->due_time?:'23:59:59')):false;
                $st=($due && $due<current_time('timestamp'))?'Overdue':'Pending';
                $wpdb->update($this->tables['tasks'],['status'=>$st,'completed_at'=>null,'completion_result'=>'','updated_at'=>current_time('mysql')],['id'=>$task_id]);
            }
        }

        $this->log_activity($done?'subtask_completed':'subtask_reopened',$sub->item_text,$task->workspace_id,$task->work_area_id,$task_id,$template_id?$occurrence_date:null);
        $this->notify_task_owners($task,'Task updated',$done?'A sub task was completed.':'A sub task was reopened.');
        wp_send_json_success(['done'=>$completed,'total'=>$total,'all_done'=>$total>0 && $completed>=$total,'parent_reopened'=>!$done]);
    }

    public function ajax_save_access(){
        $this->verify(); if(!current_user_can('bb_manage_access') && !current_user_can('manage_options')) wp_send_json_error(['message'=>'Access management permission required'],403);
        global $wpdb; $employee_id=absint($_POST['employee_id']??0); $workspace_id=absint($_POST['workspace_id']??0); if(!$employee_id||!$workspace_id) wp_send_json_error(['message'=>'User and Workspace are required']);
        $data=['employee_id'=>$employee_id,'workspace_id'=>$workspace_id,'access_role'=>sanitize_key($_POST['access_role']??'member'),'access_mode'=>in_array(($_POST['access_mode']??'allow'),['allow','deny'],true)?sanitize_key($_POST['access_mode']):'allow','can_add'=>!empty($_POST['can_add'])?1:0,'can_edit'=>!empty($_POST['can_edit'])?1:0,'can_complete'=>isset($_POST['can_complete'])?(!empty($_POST['can_complete'])?1:0):1,'can_delete'=>!empty($_POST['can_delete'])?1:0,'updated_at'=>current_time('mysql')];
        $id=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['workspace_access']} WHERE employee_id=%d AND workspace_id=%d",$employee_id,$workspace_id)); if($id) $wpdb->update($this->tables['workspace_access'],$data,['id'=>$id]); else $wpdb->insert($this->tables['workspace_access'],$data);
        $this->log_activity('access_updated','Workspace access updated',$workspace_id,0,0); wp_send_json_success();
    }
    public function ajax_delete_access(){
        $this->verify(); if(!current_user_can('bb_manage_access') && !current_user_can('manage_options')) wp_send_json_error(['message'=>'Access management permission required'],403);
        global $wpdb; $id=absint($_POST['id']??0); $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['workspace_access']} WHERE id=%d",$id)); if($row){$wpdb->delete($this->tables['workspace_access'],['id'=>$id]); $this->log_activity('access_removed','Workspace access removed',$row->workspace_id,0,0);} wp_send_json_success();
    }
    public function ajax_pause_recurrence(){
        $this->verify(); global $wpdb; $id=absint($_POST['id']??0); $t=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d AND is_template=1",$id)); if(!$t) wp_send_json_error(['message'=>'Recurring task not found']); if(!$this->workspace_permission($t->workspace_id,'edit')) wp_send_json_error(['message'=>'Edit permission required'],403);
        $today=current_time('Y-m-d'); $wpdb->update($this->tables['tasks'],['recurrence_status'=>'paused','recurrence_paused_from'=>$today,'updated_at'=>current_time('mysql')],['id'=>$id]); $this->log_activity('recurrence_paused',$t->task_name,$t->workspace_id,$t->work_area_id,$id); wp_send_json_success();
    }
    public function ajax_resume_recurrence(){
        $this->verify(); global $wpdb; $id=absint($_POST['id']??0); $t=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d AND is_template=1",$id)); if(!$t) wp_send_json_error(['message'=>'Recurring task not found']); if(!$this->workspace_permission($t->workspace_id,'edit')) wp_send_json_error(['message'=>'Edit permission required'],403);
        if($t->recurrence_paused_from){ foreach($this->occurrence_dates_unfiltered($t,$t->recurrence_paused_from,current_time('Y-m-d')) as $d){ $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$this->tables['recurrence_exceptions']} (task_id,occurrence_date,exception_type,note,created_by,created_at) VALUES (%d,%s,'Skipped','Skipped while recurrence was paused',%d,%s)",$id,$d,get_current_user_id(),current_time('mysql'))); } }
        $wpdb->update($this->tables['tasks'],['recurrence_status'=>'active','recurrence_paused_from'=>null,'updated_at'=>current_time('mysql')],['id'=>$id]); $this->log_activity('recurrence_resumed',$t->task_name,$t->workspace_id,$t->work_area_id,$id); wp_send_json_success();
    }
    private function occurrence_dates_unfiltered($tpl,$from,$to){ $out=[]; if(!$tpl->start_date||$from>$to)return $out; $date=$tpl->start_date;$g=0;while($date&&$date<$from&&$g<5000){$date=$this->next_occurrence($date,$tpl);$g++;}while($date&&$date<=$to&&$g<7000){if(!$tpl->end_date||$date<=$tpl->end_date)$out[]=$date;if($tpl->end_date&&$date>=$tpl->end_date)break;$date=$this->next_occurrence($date,$tpl);$g++;}return $out; }
    public function ajax_skip_next_occurrence(){
        $this->verify(); global $wpdb; $id=absint($_POST['id']??0); $t=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d AND is_template=1",$id)); if(!$t) wp_send_json_error(['message'=>'Recurring task not found']); if(!$this->workspace_permission($t->workspace_id,'edit')) wp_send_json_error(['message'=>'Edit permission required'],403);
        $dates=$this->occurrence_dates_unfiltered($t,current_time('Y-m-d'),(new DateTime(current_time('Y-m-d')))->modify('+5 years')->format('Y-m-d')); $next=''; foreach($dates as $d){ $has_history=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['history']} WHERE task_id=%d AND occurrence_date=%s LIMIT 1",$id,$d)); $due=strtotime($d.' '.($t->due_time?:'23:59:59')); if(!$this->is_exception($id,$d) && !$has_history && (!$due || $due>=current_time('timestamp'))){ $next=$d; break; } } if(!$next) wp_send_json_error(['message'=>'No future occurrence found']);
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$this->tables['recurrence_exceptions']} (task_id,occurrence_date,exception_type,note,created_by,created_at) VALUES (%d,%s,'Skipped','Skipped manually',%d,%s)",$id,$next,get_current_user_id(),current_time('mysql'))); $this->log_activity('occurrence_skipped',$t->task_name.' — '.$next,$t->workspace_id,$t->work_area_id,$id,$next); wp_send_json_success(['date'=>$next]);
    }


    public function ajax_upload_attachment(){
        $this->verify(); global $wpdb;
        $raw=sanitize_text_field($_POST['task_id']??''); $occurrence=sanitize_text_field($_POST['occurrence_date']??'')?:null;
        [$template_id,$virtual_date]=$this->parse_virtual_id($raw);
        $task_id=$template_id?:absint($raw); if(!$task_id) wp_send_json_error(['message'=>'Task is required']);
        $task=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d",$task_id));
        if(!$task) wp_send_json_error(['message'=>'Task not found']);
        if(!$this->workspace_permission($task->workspace_id,'edit') && !$this->workspace_permission($task->workspace_id,'complete')) wp_send_json_error(['message'=>'Attachment permission required'],403);
        if(empty($_FILES['file'])) wp_send_json_error(['message'=>'Choose a file']);
        require_once ABSPATH.'wp-admin/includes/file.php'; require_once ABSPATH.'wp-admin/includes/media.php'; require_once ABSPATH.'wp-admin/includes/image.php';
        $allowed=['image/jpeg','image/png','image/webp','image/gif','application/pdf','text/plain','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        $type=wp_check_filetype_and_ext($_FILES['file']['tmp_name'],$_FILES['file']['name']);
        if(empty($type['type']) || !in_array($type['type'],$allowed,true)) wp_send_json_error(['message'=>'File type not allowed. Use image, PDF, Word, Excel or text files.']);
        $attachment_id=media_handle_upload('file',0);
        if(is_wp_error($attachment_id)) wp_send_json_error(['message'=>$attachment_id->get_error_message()]);
        $kind=sanitize_key($_POST['kind']??'reference'); if(!in_array($kind,['reference','proof'],true)) $kind='reference';
        if($virtual_date) $occurrence=$virtual_date;
        $wpdb->insert($this->tables['attachments'],['task_id'=>$task_id,'occurrence_date'=>$occurrence,'attachment_id'=>$attachment_id,'attachment_kind'=>$kind,'uploaded_by'=>get_current_user_id(),'created_at'=>current_time('mysql')]);
        $this->log_activity('attachment_uploaded',get_the_title($attachment_id),$task->workspace_id,$task->work_area_id,$task_id,$occurrence);
        wp_send_json_success(['id'=>$wpdb->insert_id,'attachment_id'=>$attachment_id,'url'=>wp_get_attachment_url($attachment_id),'name'=>get_the_title($attachment_id)]);
    }

    public function ajax_delete_attachment(){
        $this->verify(); global $wpdb; $id=absint($_POST['id']??0);
        $row=$wpdb->get_row($wpdb->prepare("SELECT a.*,t.workspace_id,t.work_area_id FROM {$this->tables['attachments']} a JOIN {$this->tables['tasks']} t ON t.id=a.task_id WHERE a.id=%d",$id));
        if(!$row) wp_send_json_error(['message'=>'Attachment not found']);
        if(!$this->workspace_permission($row->workspace_id,'edit') && (int)$row->uploaded_by!==get_current_user_id()) wp_send_json_error(['message'=>'You cannot delete this attachment'],403);
        wp_delete_attachment($row->attachment_id,true); $wpdb->delete($this->tables['attachments'],['id'=>$id]);
        $this->log_activity('attachment_deleted','Attachment removed',$row->workspace_id,$row->work_area_id,$row->task_id,$row->occurrence_date);
        wp_send_json_success();
    }

    public function ajax_mark_notifications_read(){
        $this->verify(); global $wpdb;
        $emp=$this->current_employee();
        if($emp) $wpdb->query($wpdb->prepare("UPDATE {$this->tables['notifications']} SET is_read=1 WHERE employee_id=%d OR wp_user_id=%d",$emp->id,get_current_user_id()));
        else $wpdb->query($wpdb->prepare("UPDATE {$this->tables['notifications']} SET is_read=1 WHERE wp_user_id=%d",get_current_user_id()));
        wp_send_json_success();
    }

    public function ajax_save_notification_settings(){
        $this->verify(); $this->require_manage();
        update_option('bb_notify_assignment',!empty($_POST['assignment'])?1:0);
        update_option('bb_notify_due_soon',0);
        update_option('bb_notify_overdue',0);
        update_option('bb_notify_daily_summary',0);
        update_option('bb_notify_morning_pending',!empty($_POST['morning_pending'])?1:0);
        update_option('bb_notify_email',!empty($_POST['email'])?1:0);
        update_option('bb_meta_whatsapp_enabled',!empty($_POST['whatsapp'])?1:0);
        update_option('bb_meta_whatsapp_phone_number_id',preg_replace('/\D+/','',(string)($_POST['meta_phone_number_id']??'')));
        $version=trim(sanitize_text_field($_POST['meta_api_version']??'v25.0'));
        if(!preg_match('/^v\d+\.\d+$/',$version)) $version='v25.0';
        update_option('bb_meta_whatsapp_api_version',$version);
        update_option('bb_meta_whatsapp_country_code',preg_replace('/\D+/','',(string)($_POST['meta_country_code']??'91')) ?: '91');
        update_option('bb_meta_tpl_assignment',sanitize_key($_POST['meta_tpl_assignment']??'workboard_task_assigned'));
        update_option('bb_meta_tpl_assignment_enabled',!empty($_POST['meta_tpl_assignment_enabled'])?1:0);
        update_option('bb_meta_tpl_update',sanitize_key($_POST['meta_tpl_update']??'workboard_task_updated'));
        update_option('bb_meta_tpl_update_enabled',!empty($_POST['meta_tpl_update_enabled'])?1:0);
        update_option('bb_meta_tpl_due',sanitize_key($_POST['meta_tpl_due']??'workboard_task_due'));
        update_option('bb_meta_tpl_due_enabled',!empty($_POST['meta_tpl_due_enabled'])?1:0);
        update_option('bb_meta_tpl_overdue',sanitize_key($_POST['meta_tpl_overdue']??'workboard_task_overdue'));
        update_option('bb_meta_tpl_overdue_enabled',!empty($_POST['meta_tpl_overdue_enabled'])?1:0);
        update_option('bb_meta_tpl_daily_enabled',0);
        update_option('bb_meta_tpl_morning',sanitize_key($_POST['meta_tpl_morning']??'workboard_pending_morningreminder'));
        update_option('bb_meta_tpl_morning_enabled',!empty($_POST['meta_tpl_morning_enabled'])?1:0);
        update_option('bb_meta_tpl_urgent',sanitize_key($_POST['meta_tpl_urgent']??'workboard_urgent_reminder'));
        update_option('bb_meta_tpl_urgent_enabled',!empty($_POST['meta_tpl_urgent_enabled'])?1:0);
        update_option('bb_meta_tpl_due_reminder',sanitize_key($_POST['meta_tpl_due_reminder']??'workboard_due_reminder'));
        update_option('bb_meta_tpl_due_reminder_enabled',!empty($_POST['meta_tpl_due_reminder_enabled'])?1:0);
        update_option('bb_meta_tpl_reminder',sanitize_key($_POST['meta_tpl_reminder']??'workboard_task_reminder'));
        update_option('bb_meta_tpl_reminder_enabled',!empty($_POST['meta_tpl_reminder_enabled'])?1:0);
        update_option('bb_meta_whatsapp_language','en');
        update_option('bb_meta_whatsapp_test_mobile',preg_replace('/[^0-9+]/','',(string)($_POST['meta_test_mobile']??'')));
        wp_send_json_success();
    }

    public function ajax_test_meta_whatsapp(){
        $this->verify(); $this->require_manage();
        $mobile=preg_replace('/[^0-9+]/','',(string)($_POST['mobile']??get_option('bb_meta_whatsapp_test_mobile','')));
        if(!$mobile) wp_send_json_error(['message'=>'Enter a test mobile number in Meta WhatsApp settings.']);
        $r=$this->send_meta_whatsapp($mobile,'Workboard Test','Test message from Workboard. Meta WhatsApp is connected successfully.','assignment',true);
        if(!empty($r['ok'])) wp_send_json_success(['message'=>$r['message']]);
        wp_send_json_error(['message'=>$r['message']??'WhatsApp test failed']);
    }


    public function ajax_send_urgent_reminder(){
        $this->verify();
        global $wpdb;
        $raw_id=sanitize_text_field((string)($_POST['task_id']??''));
        $task_id=0; $occurrence_date=null;
        if(preg_match('/^r_(\d+)(?:_(\d{4}-\d{2}-\d{2}))?/',$raw_id,$m)){
            $task_id=absint($m[1]);
            if(!empty($m[2])) $occurrence_date=$m[2];
        }else{
            $task_id=absint($raw_id);
        }
        if(!$task_id) wp_send_json_error(['message'=>'Task not found'],404);
        $task=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d",$task_id));
        if(!$task) wp_send_json_error(['message'=>'Task not found'],404);
        if(!$this->workspace_permission((int)$task->workspace_id,'edit')) wp_send_json_error(['message'=>'You cannot send reminders for this task'],403);
        if(!get_option('bb_meta_whatsapp_enabled',0)) wp_send_json_error(['message'=>'WhatsApp notifications are disabled']);
        if(!$this->whatsapp_event_enabled('urgent_update')) wp_send_json_error(['message'=>'Urgent Reminder WhatsApp is disabled']);
        $notes=sanitize_textarea_field(wp_unslash($_POST['notes']??''));
        if(trim($notes)==='') wp_send_json_error(['message'=>'Enter Notes before sending the urgent reminder'],422);

        $ids=$this->task_assignee_employee_ids($task_id,absint($task->assignee_id));
        if(!$ids) wp_send_json_error(['message'=>'No Assignee selected for this task']);

        $sent=0; $skipped=0; $failed=[];
        foreach($ids as $eid){
            $emp=$wpdb->get_row($wpdb->prepare("SELECT id,name,mobile FROM {$this->tables['employees']} WHERE id=%d AND status='active'",absint($eid)));
            if(!$emp || empty($emp->mobile)){ $skipped++; continue; }
            $r=$this->send_meta_whatsapp($emp->mobile,'Urgent Reminder',$notes,'urgent_update',false,$task_id,$occurrence_date,(int)$eid);
            if(!empty($r['ok'])) $sent++;
            else $failed[]=trim((string)($emp->name??'Assignee')).': '.sanitize_text_field((string)($r['message']??'Send failed'));
        }

        if($sent){
            $this->log_activity('urgent_reminder_sent','Urgent reminder sent to '.$sent.' Assignee'.($sent===1?'':'s').' · Notes: '.mb_substr($notes,0,180),$task->workspace_id,$task->work_area_id,$task_id,$occurrence_date);
            $msg='Urgent reminder sent to '.$sent.' Assignee'.($sent===1?'':'s').'.';
            if($skipped) $msg.=' '.$skipped.' skipped (mobile not available).';
            if($failed) $msg.=' '.count($failed).' failed.';
            wp_send_json_success(['message'=>$msg,'sent'=>$sent,'skipped'=>$skipped,'failed'=>count($failed)]);
        }
        $message=$failed ? implode(' | ',$failed) : ($skipped?'Assignee mobile number is not available.':'Urgent reminder could not be sent.');
        wp_send_json_error(['message'=>$message,'sent'=>0,'skipped'=>$skipped,'failed'=>count($failed)]);
    }


    /* Telegram Task Inbox -------------------------------------------------- */
    public function register_telegram_rest_route(){
        register_rest_route('business-board/v1','/telegram-webhook',[
            'methods'=>'POST','callback'=>[$this,'telegram_webhook'],'permission_callback'=>'__return_true'
        ]);
    }

    private function telegram_api($method,$params=[]){
        $token=trim((string)get_option('bb_telegram_bot_token',''));
        if(!$token) return ['ok'=>false,'message'=>'Telegram Bot Token is not configured'];
        $url='https://api.telegram.org/bot'.$token.'/'.rawurlencode($method);
        $r=wp_remote_post($url,['timeout'=>20,'headers'=>['Content-Type'=>'application/json'],'body'=>wp_json_encode($params)]);
        if(is_wp_error($r)) return ['ok'=>false,'message'=>$r->get_error_message()];
        $body=json_decode((string)wp_remote_retrieve_body($r),true);
        if(!is_array($body) || empty($body['ok'])) return ['ok'=>false,'message'=>sanitize_text_field($body['description']??'Telegram API request failed'),'raw'=>$body];
        return ['ok'=>true,'result'=>$body['result']??null,'message'=>'OK'];
    }

    private function telegram_set_webhook(){
        $secret=(string)get_option('bb_telegram_webhook_secret','');
        if(!$secret){ $secret=wp_generate_password(36,false,false); update_option('bb_telegram_webhook_secret',$secret,false); }
        return $this->telegram_api('setWebhook',[
            'url'=>rest_url('business-board/v1/telegram-webhook'),
            'secret_token'=>$secret,
            'allowed_updates'=>['message','callback_query'],
            'drop_pending_updates'=>false,
        ]);
    }

    private function telegram_send_test(){
        $chat=(string)get_option('bb_telegram_group_chat_id','');
        if(!$chat) return ['ok'=>false,'message'=>'Allowed Group Chat ID is blank. Send /chatid in your Telegram group first.'];
        return $this->telegram_api('sendMessage',['chat_id'=>$chat,'text'=>'Workboard Telegram Task Inbox is connected successfully.']);
    }

    private function telegram_connection_status(){
        $me=$this->telegram_api('getMe',[]);
        if(empty($me['ok'])) return ['ok'=>false,'message'=>'Bot Token check failed: '.($me['message']??'Unknown Telegram error')];
        $hook=$this->telegram_api('getWebhookInfo',[]);
        if(empty($hook['ok'])) return ['ok'=>false,'message'=>'Webhook status check failed: '.($hook['message']??'Unknown Telegram error')];
        $bot=is_array($me['result']??null)?$me['result']:[];
        $wh=is_array($hook['result']??null)?$hook['result']:[];
        return [
            'ok'=>true,
            'bot_username'=>sanitize_text_field((string)($bot['username']??'')),
            'bot_name'=>sanitize_text_field((string)($bot['first_name']??'')),
            'webhook_url'=>esc_url_raw((string)($wh['url']??'')),
            'pending_updates'=>absint($wh['pending_update_count']??0),
            'last_error'=>sanitize_text_field((string)($wh['last_error_message']??'')),
        ];
    }

    public function ajax_save_telegram_settings(){
        $this->verify(); $this->require_manage();
        $enabled=!empty($_POST['telegram_enabled'])?1:0;
        update_option('bb_telegram_enabled',$enabled);
        $tg_token=trim((string)wp_unslash($_POST['telegram_bot_token']??''));
        if($tg_token!=='') update_option('bb_telegram_bot_token',sanitize_text_field($tg_token),false);
        $chat=preg_replace('/[^0-9\-]/','',(string)wp_unslash($_POST['telegram_group_chat_id']??''));
        update_option('bb_telegram_group_chat_id',$chat);
        $secret=(string)get_option('bb_telegram_webhook_secret','');
        if(!$secret){ $secret=wp_generate_password(36,false,false); update_option('bb_telegram_webhook_secret',$secret,false); }

        $token=(string)get_option('bb_telegram_bot_token','');
        if(!$enabled){
            wp_send_json_success(['message'=>'Telegram Task Inbox settings saved. Telegram is currently disabled.','enabled'=>0,'chat_id'=>$chat,'webhook_url'=>rest_url('business-board/v1/telegram-webhook')]);
        }
        if(!$token) wp_send_json_error(['message'=>'Enter the Bot Token from BotFather before connecting.'],400);

        $me=$this->telegram_api('getMe',[]);
        if(empty($me['ok'])) wp_send_json_error(['message'=>'Bot Token is not valid: '.($me['message']??'Telegram rejected the token')],400);
        $hook=$this->telegram_set_webhook();
        if(empty($hook['ok'])) wp_send_json_error(['message'=>'Settings were saved, but Telegram could not connect the webhook: '.($hook['message']??'Unknown error')],400);

        $status=$this->telegram_connection_status();
        $bot=is_array($me['result']??null)?$me['result']:[];
        $username=sanitize_text_field((string)($bot['username']??''));
        $msg='Saved and connected'.($username?' to @'.$username:'').'.';
        if(!empty($status['last_error'])) $msg.=' Telegram reports: '.$status['last_error'];

        if(!empty($_POST['test_telegram'])){
            if(!$chat) wp_send_json_error(['message'=>$msg.' Add the Allowed Group Chat ID before testing the group.'],400);
            $test=$this->telegram_send_test();
            if(empty($test['ok'])) wp_send_json_error(['message'=>$msg.' Group test failed: '.($test['message']??'Unknown Telegram error')],400);
            $msg.=' Test message sent to the Telegram group.';
        }

        wp_send_json_success([
            'message'=>$msg,
            'enabled'=>1,
            'chat_id'=>$chat,
            'bot_username'=>$username,
            'webhook_url'=>rest_url('business-board/v1/telegram-webhook'),
            'pending_updates'=>absint($status['pending_updates']??0),
        ]);
    }

    public function ajax_test_telegram(){
        $this->verify(); $this->require_manage();
        $status=$this->telegram_connection_status();
        if(empty($status['ok'])) wp_send_json_error(['message'=>$status['message']??'Telegram connection check failed']);
        $r=$this->telegram_send_test();
        if(!empty($r['ok'])) wp_send_json_success(['message'=>'Telegram test message sent.','bot_username'=>$status['bot_username']??'','webhook_url'=>$status['webhook_url']??'']);
        wp_send_json_error(['message'=>$r['message']??'Telegram test failed']);
    }

    private function telegram_state_key($chat_id,$user_id){ return 'bb_tg_'.md5(get_current_blog_id().'|'.$chat_id.'|'.$user_id); }
    private function telegram_get_state($chat_id,$user_id){ $v=get_transient($this->telegram_state_key($chat_id,$user_id)); return is_array($v)?$v:[]; }
    private function telegram_put_state($chat_id,$user_id,$state){ set_transient($this->telegram_state_key($chat_id,$user_id),$state,4*HOUR_IN_SECONDS); }
    private function telegram_clear_state($chat_id,$user_id){ delete_transient($this->telegram_state_key($chat_id,$user_id)); }

    private function telegram_keyboard($rows){ return ['inline_keyboard'=>$rows]; }
    private function telegram_btn($text,$data){ return ['text'=>$text,'callback_data'=>$data]; }
    private function telegram_send($chat_id,$text,$rows=[]){
        $p=['chat_id'=>$chat_id,'text'=>$text,'disable_web_page_preview'=>true];
        if($rows) $p['reply_markup']=$this->telegram_keyboard($rows);
        return $this->telegram_api('sendMessage',$p);
    }
    private function telegram_edit($chat_id,$message_id,$text,$rows=[]){
        $p=['chat_id'=>$chat_id,'message_id'=>$message_id,'text'=>$text,'disable_web_page_preview'=>true];
        if($rows) $p['reply_markup']=$this->telegram_keyboard($rows);
        return $this->telegram_api('editMessageText',$p);
    }
    private function telegram_answer_callback($callback_id,$text=''){ return $this->telegram_api('answerCallbackQuery',['callback_query_id'=>$callback_id,'text'=>$text]); }
    private function telegram_prompt($chat_id,$message_id,$text,$rows=[]){ return $message_id?$this->telegram_edit($chat_id,$message_id,$text,$rows):$this->telegram_send($chat_id,$text,$rows); }

    private function telegram_task_name_from_text($text){
        // Zero-AI capture: never ask the user to type the task again. Use the pasted
        // message itself as the Task Name (normalized/truncated only for the DB field),
        // while the complete original message is retained in Description/Notes.
        $flat=trim(preg_replace('/\s+/u',' ',wp_strip_all_tags((string)$text)));
        return mb_strlen($flat)>220?mb_substr($flat,0,217).'...':$flat;
    }

    private function telegram_parse_date($text){
        $t=trim((string)$text); $d=null;
        if(preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/',$t,$m)) $d=sprintf('%04d-%02d-%02d',(int)$m[3],(int)$m[2],(int)$m[1]);
        elseif(preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$t)) $d=$t;
        if(!$d) return '';
        $dt=DateTime::createFromFormat('Y-m-d',$d); return ($dt && $dt->format('Y-m-d')===$d)?$d:'';
    }

    private function telegram_parse_time($text){
        $t=strtoupper(trim((string)$text));
        foreach(['H:i','G:i','g:i A','g A'] as $fmt){ $d=DateTime::createFromFormat('!'.$fmt,$t); if($d) return $d->format('H:i:s'); }
        return '';
    }

    private function telegram_paginate_rows($items,$prefix,$page=0,$selected=[],$per=8,$page_prefix='pg'){
        $count=count($items); $pages=max(1,(int)ceil($count/$per)); $page=max(0,min($pages-1,(int)$page)); $slice=array_slice($items,$page*$per,$per); $rows=[];
        foreach($slice as $it){ $id=(int)$it->id; $mark=in_array($id,array_map('intval',$selected),true)?'✓ ':''; $rows[]=[ $this->telegram_btn($mark.$it->name,$prefix.':'.$id) ]; }
        if($pages>1){ $nav=[]; if($page>0)$nav[]=$this->telegram_btn('◀ Prev',$page_prefix.':'.($page-1)); $nav[]=$this->telegram_btn(($page+1).' / '.$pages,'noop'); if($page<$pages-1)$nav[]=$this->telegram_btn('Next ▶',$page_prefix.':'.($page+1)); $rows[]=$nav; }
        return $rows;
    }

    private function telegram_workspace_rows($page=0){ global $wpdb; $items=$wpdb->get_results("SELECT id,name FROM {$this->tables['workspaces']} WHERE status='active' ORDER BY sort_order,id"); return $this->telegram_paginate_rows($items,'w',$page,[],8,'wpg'); }
    private function telegram_area_rows($workspace_id,$page=0){ global $wpdb; $items=$wpdb->get_results($wpdb->prepare("SELECT a.id,a.name FROM {$this->tables['workspace_areas']} wa JOIN {$this->tables['work_areas']} a ON a.id=wa.work_area_id WHERE wa.workspace_id=%d AND a.status='active' ORDER BY wa.sort_order,a.id",$workspace_id)); return $this->telegram_paginate_rows($items,'a',$page,[],8,'apg'); }
    private function telegram_employee_rows($prefix,$selected=[],$page=0,$page_prefix='upg'){ global $wpdb; $items=$wpdb->get_results("SELECT id,name FROM {$this->tables['employees']} WHERE status='active' ORDER BY name,id"); return $this->telegram_paginate_rows($items,$prefix,$page,$selected,8,$page_prefix); }

    private function telegram_show_stage($state,$chat_id,$message_id=0){
        global $wpdb; $stage=$state['stage']??'type'; $cancel=[[$this->telegram_btn('Cancel','x:cancel')]];
        if($stage==='type') return $this->telegram_prompt($chat_id,$message_id,"Task captured.\n\n".$state['task_name']."\n\nSelect Task Type:",[[ $this->telegram_btn('One Time','t:one'),$this->telegram_btn('Recurring','t:rec') ],[$this->telegram_btn('Cancel','x:cancel')]]);
        if($stage==='workspace'){ $rows=$this->telegram_workspace_rows($state['page']??0); $rows[]=$cancel[0]; return $this->telegram_prompt($chat_id,$message_id,'Select Workspace:',$rows); }
        if($stage==='area'){ $rows=$this->telegram_area_rows((int)$state['workspace_id'],$state['page']??0); $rows[]=$cancel[0]; return $this->telegram_prompt($chat_id,$message_id,'Select Work Area:',$rows); }
        if($stage==='assignee'){ $rows=$this->telegram_employee_rows('u',$state['assignees']??[],$state['page']??0,'upg'); $rows[]=[ $this->telegram_btn('Done','u:done') ]; $rows[]=$cancel[0]; return $this->telegram_prompt($chat_id,$message_id,'Select Assignee(s). Tap names to select/unselect, then Done:',$rows); }
        if($stage==='owner'){ $rows=$this->telegram_employee_rows('o',$state['owners']??[],$state['page']??0,'opg'); $rows[]=[ $this->telegram_btn('Done / Skip','o:done') ]; $rows[]=$cancel[0]; return $this->telegram_prompt($chat_id,$message_id,'Select Task Owner(s), or Done / Skip:',$rows); }
        if($stage==='priority') return $this->telegram_prompt($chat_id,$message_id,'Select Priority:',[[ $this->telegram_btn('Urgent','p:U'),$this->telegram_btn('High','p:H') ],[ $this->telegram_btn('Medium','p:M'),$this->telegram_btn('Low','p:L') ],[$this->telegram_btn('Cancel','x:cancel')]]);
        if($stage==='one_due') return $this->telegram_prompt($chat_id,$message_id,'Select Due Date:',[[ $this->telegram_btn('Today','d:t'),$this->telegram_btn('Tomorrow','d:m') ],[ $this->telegram_btn('Enter Date','d:e'),$this->telegram_btn('No Due Date','d:n') ],[$this->telegram_btn('Cancel','x:cancel')]]);
        if($stage==='one_time') return $this->telegram_prompt($chat_id,$message_id,'Due Time is optional:',[[ $this->telegram_btn('Enter Time','tm:e'),$this->telegram_btn('No Time','tm:n') ],[$this->telegram_btn('Cancel','x:cancel')]]);
        if($stage==='rec_frequency') return $this->telegram_prompt($chat_id,$message_id,'Select Recurrence:',[[ $this->telegram_btn('Daily','f:D'),$this->telegram_btn('Weekly','f:W') ],[ $this->telegram_btn('Fortnightly','f:F'),$this->telegram_btn('Monthly','f:M') ],[ $this->telegram_btn('Quarterly','f:Q'),$this->telegram_btn('Yearly','f:Y') ],[ $this->telegram_btn('Custom Interval','f:C') ],[$this->telegram_btn('Cancel','x:cancel')]]);
        if($stage==='custom_unit') return $this->telegram_prompt($chat_id,$message_id,'Select custom interval unit:',[[ $this->telegram_btn('Days','cu:D'),$this->telegram_btn('Weeks','cu:W') ],[ $this->telegram_btn('Months','cu:M'),$this->telegram_btn('Years','cu:Y') ],[$this->telegram_btn('Cancel','x:cancel')]]);
        if($stage==='rec_start') return $this->telegram_prompt($chat_id,$message_id,'Select Recurring Start Date:',[[ $this->telegram_btn('Today','sd:t'),$this->telegram_btn('Tomorrow','sd:m') ],[ $this->telegram_btn('Enter Date','sd:e') ],[$this->telegram_btn('Cancel','x:cancel')]]);
        if($stage==='rec_end') return $this->telegram_prompt($chat_id,$message_id,'End Date is optional:',[[ $this->telegram_btn('No End Date','ed:n'),$this->telegram_btn('Enter End Date','ed:e') ],[$this->telegram_btn('Cancel','x:cancel')]]);
        if($stage==='rec_time') return $this->telegram_prompt($chat_id,$message_id,'Due Time is optional for each occurrence:',[[ $this->telegram_btn('Enter Time','rt:e'),$this->telegram_btn('No Time','rt:n') ],[$this->telegram_btn('Cancel','x:cancel')]]);
        if($stage==='rec_overdue') return $this->telegram_prompt($chat_id,$message_id,'If an occurrence is not completed before the next one:',[[ $this->telegram_btn('Show Until Next','ov:u') ],[ $this->telegram_btn('Keep Overdue','ov:k') ],[$this->telegram_btn('Cancel','x:cancel')]]);
        if($stage==='reminder') return $this->telegram_prompt($chat_id,$message_id,'Set Reminder?',[[ $this->telegram_btn('No Reminder','r:n'),$this->telegram_btn('Set Reminder','r:y') ],[$this->telegram_btn('Cancel','x:cancel')]]);
        if($stage==='reminder_unit'){
            $row=[]; if(!empty($state['due_time']))$row[]=$this->telegram_btn('Minutes','ru:M'); $row[]=$this->telegram_btn('Hours','ru:H');
            return $this->telegram_prompt($chat_id,$message_id,'Select reminder unit:',[$row,[ $this->telegram_btn('Days','ru:D'),$this->telegram_btn('Weeks','ru:W') ],[$this->telegram_btn('Cancel','x:cancel')]]);
        }
        if($stage==='confirm'){
            $preview=$this->telegram_preview($state);
            return $this->telegram_prompt($chat_id,$message_id,$preview,[[ $this->telegram_btn('✅ Create Task','c:create') ],[ $this->telegram_btn('Cancel','x:cancel') ]]);
        }
        return null;
    }

    private function telegram_preview($s){
        global $wpdb; $ws=$wpdb->get_var($wpdb->prepare("SELECT name FROM {$this->tables['workspaces']} WHERE id=%d",(int)($s['workspace_id']??0))); $wa=$wpdb->get_var($wpdb->prepare("SELECT name FROM {$this->tables['work_areas']} WHERE id=%d",(int)($s['work_area_id']??0)));
        $names=function($ids)use($wpdb){ if(!$ids)return '—'; $ids=array_values(array_filter(array_map('absint',$ids))); if(!$ids)return '—'; $ph=implode(',',array_fill(0,count($ids),'%d')); return implode(', ',$wpdb->get_col($wpdb->prepare("SELECT name FROM {$this->tables['employees']} WHERE id IN ($ph) ORDER BY name",...$ids)))?:'—'; };
        $due='—'; if(($s['task_type']??'')==='One Time'){ $due=$s['due_date']??'—'; if(!empty($s['due_time']))$due.=' · '.substr($s['due_time'],0,5); }
        else { $due=($s['frequency']??'Recurring').' from '.($s['start_date']??current_time('Y-m-d')); if(!empty($s['end_date']))$due.=' to '.$s['end_date']; if(!empty($s['due_time']))$due.=' · '.substr($s['due_time'],0,5); }
        $rem=!empty($s['reminder_enabled'])?((int)$s['reminder_value'].' '.($s['reminder_unit']??'Hours').' before'):'No';
        return "Ready to create task:\n\nTask: ".($s['task_name']??'')."\nType: ".($s['task_type']??'One Time')."\nWorkspace: ".$ws."\nWork Area: ".$wa."\nAssignee: ".$names($s['assignees']??[])."\nOwner: ".$names($s['owners']??[])."\nPriority: ".($s['priority']??'Medium')."\nDue / Recurrence: ".$due."\nReminder: ".$rem;
    }

    private function telegram_after_time($s){ $s['stage']='reminder'; return $s; }

    private function telegram_create_task($s){
        global $wpdb; $wid=absint($s['workspace_id']??0); $aid=absint($s['work_area_id']??0); if(!$wid||!$aid)return ['ok'=>false,'message'=>'Workspace or Work Area is missing'];
        $mapped=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['workspace_areas']} WHERE workspace_id=%d AND work_area_id=%d",$wid,$aid)); if(!$mapped)return ['ok'=>false,'message'=>'Selected Work Area is no longer attached to the Workspace'];
        $assignees=array_values(array_unique(array_filter(array_map('absint',$s['assignees']??[]))));
        if(!$assignees){ $fallback=absint($wpdb->get_var($wpdb->prepare("SELECT employee_id FROM {$this->tables['entity_assignments']} WHERE entity_type='work_area' AND entity_id=%d AND relation_type='assignee' AND archived_at IS NULL ORDER BY id LIMIT 1",$aid))); if(!$fallback)$fallback=absint($wpdb->get_var($wpdb->prepare("SELECT head_employee_id FROM {$this->tables['work_areas']} WHERE id=%d",$aid))); if($fallback)$assignees=[$fallback]; }
        $owners=array_values(array_unique(array_filter(array_map('absint',$s['owners']??[])))); $is_rec=(($s['task_type']??'')==='Recurring');
        $task_name=sanitize_text_field($s['task_name']??'');
        if(!$task_name) return ['ok'=>false,'message'=>'Task text is empty'];
        $task_name_length=$this->task_name_length($task_name);
        if($task_name_length>220) return ['ok'=>false,'message'=>'Task name cannot exceed 220 characters. Please reduce by '.($task_name_length-220).' character'.(($task_name_length-220)===1?'':'s').'.'];
        $data=['task_name'=>$task_name,'slug'=>$this->unique_task_slug($task_name),'workspace_id'=>$wid,'work_area_id'=>$aid,'assignee_id'=>$assignees[0]??null,'priority'=>$this->normalize_priority($s['priority']??'Medium')?:'Medium','task_type'=>$is_rec?'Recurring':'One Time','is_template'=>$is_rec?1:0,'frequency'=>$is_rec?sanitize_text_field($s['frequency']??'Daily'):'None','interval_value'=>$is_rec?max(1,absint($s['interval_value']??1)):1,'interval_unit'=>$is_rec?sanitize_text_field($s['interval_unit']??'Days'):'Days','start_date'=>$is_rec?(sanitize_text_field($s['start_date']??current_time('Y-m-d'))):null,'end_date'=>$is_rec?(sanitize_text_field($s['end_date']??'')?:null):null,'due_date'=>$is_rec?null:(sanitize_text_field($s['due_date']??'')?:null),'due_time'=>sanitize_text_field($s['due_time']??'')?:null,'occurrence_date'=>$is_rec?null:(sanitize_text_field($s['due_date']??'')?:null),'description'=>sanitize_textarea_field("Captured from Telegram:\n\n".($s['source_text']??'')),'labels'=>'','estimated_minutes'=>0,'notify_enabled'=>1,'reminder_enabled'=>!empty($s['reminder_enabled'])?1:0,'reminder_value'=>max(1,absint($s['reminder_value']??1)),'reminder_unit'=>sanitize_text_field($s['reminder_unit']??'Hours'),'recurrence_status'=>'active','overdue_policy'=>$is_rec&&(($s['overdue_policy']??'until_next')==='keep')?'keep':'until_next','status'=>$is_rec?'Template':'Pending','updated_at'=>current_time('mysql')];
        if(!$is_rec && !empty($data['due_date']) && $data['due_date']<current_time('Y-m-d'))return ['ok'=>false,'message'=>'Due Date cannot be earlier than today'];
        $ok=$wpdb->insert($this->tables['tasks'],$data); if($ok===false)return ['ok'=>false,'message'=>'Could not create task']; $task_id=(int)$wpdb->insert_id;
        $items=[]; foreach($assignees as $eid)$items[]=['employee_id'=>$eid,'relation_type'=>'assignee','rights'=>'view']; foreach($owners as $eid)$items[]=['employee_id'=>$eid,'relation_type'=>'owner','rights'=>'view_edit']; if($items)$this->save_entity_assignments_internal('task',$task_id,$items);
        $task=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d",$task_id)); if($assignees)$this->notify_task_assignment($task,$assignees); $this->log_activity('task_created',$data['task_name'],$wid,$aid,$task_id);
        return ['ok'=>true,'task_id'=>$task_id,'task'=>$task,'url'=>$this->task_url($task)];
    }

    private function telegram_handle_message($m){
        if(!is_array($m)||!empty($m['from']['is_bot']))return; $chat_id=(string)($m['chat']['id']??''); $user_id=(string)($m['from']['id']??''); if(!$chat_id||!$user_id)return; $text=trim((string)($m['text']??$m['caption']??'')); if($text==='')return;
        if(preg_match('/^\/chatid(?:@\w+)?$/i',$text)){ $this->telegram_send($chat_id,'This Telegram Group Chat ID is: '.$chat_id); return; }
        $allowed=(string)get_option('bb_telegram_group_chat_id',''); if(!$allowed || $chat_id!==$allowed){ if(preg_match('/^\/(start|help)(?:@\w+)?$/i',$text))$this->telegram_send($chat_id,'This group is not connected to Workboard yet. Send /chatid, then save that ID in Workboard → Settings → Telegram Task Inbox.'); return; }
        if(preg_match('/^\/cancel(?:@\w+)?$/i',$text)){ $this->telegram_clear_state($chat_id,$user_id); $this->telegram_send($chat_id,'Task capture cancelled.'); return; }
        if(preg_match('/^\/(start|help)(?:@\w+)?$/i',$text)){ $this->telegram_send($chat_id,"Paste any WhatsApp, SMS, email or other task message here. I will use the pasted text as the task, then ask Task Type, Workspace, Work Area and the remaining fields.\n\nUse /cancel anytime."); return; }
        $s=$this->telegram_get_state($chat_id,$user_id); $stage=$s['stage']??'';
        if($stage==='await_due_date'){ $d=$this->telegram_parse_date($text); if(!$d){$this->telegram_send($chat_id,'Enter a valid date as DD/MM/YYYY or YYYY-MM-DD.');return;} if($d<current_time('Y-m-d')){$this->telegram_send($chat_id,'Due Date cannot be earlier than today.');return;} $s['due_date']=$d;$s['stage']='one_time';$this->telegram_put_state($chat_id,$user_id,$s);$this->telegram_show_stage($s,$chat_id);return; }
        if($stage==='await_due_time'){ $t=$this->telegram_parse_time($text); if(!$t){$this->telegram_send($chat_id,'Enter time like 17:30 or 5:30 PM.');return;} $s['due_time']=$t;$s=$this->telegram_after_time($s);$this->telegram_put_state($chat_id,$user_id,$s);$this->telegram_show_stage($s,$chat_id);return; }
        if($stage==='await_custom_value'){ $n=absint($text); if($n<1){$this->telegram_send($chat_id,'Enter a number greater than 0.');return;} $s['interval_value']=$n;$s['stage']='custom_unit';$this->telegram_put_state($chat_id,$user_id,$s);$this->telegram_show_stage($s,$chat_id);return; }
        if($stage==='await_start_date'){ $d=$this->telegram_parse_date($text); if(!$d){$this->telegram_send($chat_id,'Enter a valid start date as DD/MM/YYYY or YYYY-MM-DD.');return;} if($d<current_time('Y-m-d')){$this->telegram_send($chat_id,'Start Date cannot be earlier than today.');return;} $s['start_date']=$d;$s['stage']='rec_end';$this->telegram_put_state($chat_id,$user_id,$s);$this->telegram_show_stage($s,$chat_id);return; }
        if($stage==='await_end_date'){ $d=$this->telegram_parse_date($text); if(!$d){$this->telegram_send($chat_id,'Enter a valid end date as DD/MM/YYYY or YYYY-MM-DD.');return;} if(!empty($s['start_date'])&&$d<$s['start_date']){$this->telegram_send($chat_id,'End Date cannot be earlier than Start Date.');return;} $s['end_date']=$d;$s['stage']='rec_time';$this->telegram_put_state($chat_id,$user_id,$s);$this->telegram_show_stage($s,$chat_id);return; }
        if($stage==='await_rec_time'){ $t=$this->telegram_parse_time($text); if(!$t){$this->telegram_send($chat_id,'Enter time like 17:30 or 5:30 PM.');return;} $s['due_time']=$t;$s['stage']='rec_overdue';$this->telegram_put_state($chat_id,$user_id,$s);$this->telegram_show_stage($s,$chat_id);return; }
        if($stage==='await_reminder_value'){ $n=absint($text); if($n<1||$n>9999){$this->telegram_send($chat_id,'Enter a reminder number from 1 to 9999.');return;} $s['reminder_value']=$n;$s['stage']='reminder_unit';$this->telegram_put_state($chat_id,$user_id,$s);$this->telegram_show_stage($s,$chat_id);return; }
        if($s){ $this->telegram_send($chat_id,'Please use the buttons in the current task capture, or send /cancel to start over.'); return; }
        $s=['stage'=>'type','source_text'=>$text,'task_name'=>$this->telegram_task_name_from_text($text),'task_type'=>'','workspace_id'=>0,'work_area_id'=>0,'assignees'=>[],'owners'=>[],'priority'=>'Medium','reminder_enabled'=>0,'reminder_value'=>1,'reminder_unit'=>'Hours','page'=>0,'started_at'=>time()]; $this->telegram_put_state($chat_id,$user_id,$s); $this->telegram_show_stage($s,$chat_id);
    }

    private function telegram_handle_callback($cb){
        $id=(string)($cb['id']??''); $data=(string)($cb['data']??''); $chat_id=(string)($cb['message']['chat']['id']??''); $message_id=absint($cb['message']['message_id']??0); $user_id=(string)($cb['from']['id']??''); if(!$chat_id||!$user_id)return;
        $allowed=(string)get_option('bb_telegram_group_chat_id',''); if(!$allowed||$chat_id!==$allowed){ if($id)$this->telegram_answer_callback($id,'This group is not connected.'); return; }
        if($data==='noop'){ if($id)$this->telegram_answer_callback($id); return; }
        $s=$this->telegram_get_state($chat_id,$user_id); if(!$s){ if($id)$this->telegram_answer_callback($id,'This task capture expired. Paste the message again.'); return; }
        if($data==='x:cancel'){ $this->telegram_clear_state($chat_id,$user_id); if($id)$this->telegram_answer_callback($id,'Cancelled'); $this->telegram_prompt($chat_id,$message_id,'Task capture cancelled.'); return; }
        $stage=$s['stage']??'';
        if($stage==='type'&&strpos($data,'t:')===0){ $s['task_type']=$data==='t:rec'?'Recurring':'One Time'; $s['stage']='workspace';$s['page']=0; }
        elseif($stage==='workspace'&&strpos($data,'w:')===0){ $s['workspace_id']=absint(substr($data,2));$s['stage']='area';$s['page']=0; }
        elseif($stage==='workspace'&&strpos($data,'wpg:')===0){$s['page']=absint(substr($data,4));}
        elseif($stage==='area'&&strpos($data,'a:')===0){ $s['work_area_id']=absint(substr($data,2));$s['stage']='assignee';$s['page']=0; }
        elseif($stage==='area'&&strpos($data,'apg:')===0){$s['page']=absint(substr($data,4));}
        elseif($stage==='assignee'&&$data==='u:done'){ $s['stage']='owner';$s['page']=0; }
        elseif($stage==='assignee'&&strpos($data,'u:')===0){$eid=absint(substr($data,2));$arr=array_map('intval',$s['assignees']??[]);if(in_array($eid,$arr,true))$arr=array_values(array_diff($arr,[$eid]));else$arr[]=$eid;$s['assignees']=$arr;}
        elseif($stage==='assignee'&&strpos($data,'upg:')===0){$s['page']=absint(substr($data,4));}
        elseif($stage==='owner'&&$data==='o:done'){ $s['stage']='priority';$s['page']=0; }
        elseif($stage==='owner'&&strpos($data,'o:')===0){$eid=absint(substr($data,2));$arr=array_map('intval',$s['owners']??[]);if(in_array($eid,$arr,true))$arr=array_values(array_diff($arr,[$eid]));else$arr[]=$eid;$s['owners']=$arr;}
        elseif($stage==='owner'&&strpos($data,'opg:')===0){$s['page']=absint(substr($data,4));}
        elseif($stage==='priority'&&strpos($data,'p:')===0){$map=['U'=>'Urgent','H'=>'High','M'=>'Medium','L'=>'Low'];$s['priority']=$map[substr($data,2)]??'Medium';$s['stage']=$s['task_type']==='Recurring'?'rec_frequency':'one_due';}
        elseif($stage==='one_due'&&strpos($data,'d:')===0){$v=substr($data,2);if($v==='t'){$s['due_date']=current_time('Y-m-d');$s['stage']='one_time';}elseif($v==='m'){$s['due_date']=date('Y-m-d',strtotime(current_time('Y-m-d').' +1 day'));$s['stage']='one_time';}elseif($v==='n'){$s['due_date']='';$s['due_time']='';$s['reminder_enabled']=0;$s['stage']='confirm';}else{$s['stage']='await_due_date';$this->telegram_put_state($chat_id,$user_id,$s);if($id)$this->telegram_answer_callback($id);$this->telegram_prompt($chat_id,$message_id,'Enter Due Date as DD/MM/YYYY or YYYY-MM-DD.');return;}}
        elseif($stage==='one_time'&&strpos($data,'tm:')===0){if($data==='tm:n'){$s['due_time']='';$s['stage']='reminder';}else{$s['stage']='await_due_time';$this->telegram_put_state($chat_id,$user_id,$s);if($id)$this->telegram_answer_callback($id);$this->telegram_prompt($chat_id,$message_id,'Enter Due Time, for example 17:30 or 5:30 PM.');return;}}
        elseif($stage==='rec_frequency'&&strpos($data,'f:')===0){$v=substr($data,2);$map=['D'=>['Daily',1,'Days'],'W'=>['Weekly',1,'Weeks'],'F'=>['Fortnightly',2,'Weeks'],'M'=>['Monthly',1,'Months'],'Q'=>['Quarterly',3,'Months'],'Y'=>['Yearly',1,'Years']];if($v==='C'){$s['frequency']='Custom Interval';$s['stage']='await_custom_value';$this->telegram_put_state($chat_id,$user_id,$s);if($id)$this->telegram_answer_callback($id);$this->telegram_prompt($chat_id,$message_id,'Enter the custom interval number, for example 3.');return;}[$s['frequency'],$s['interval_value'],$s['interval_unit']]=$map[$v]??['Daily',1,'Days'];$s['stage']='rec_start';}
        elseif($stage==='custom_unit'&&strpos($data,'cu:')===0){$map=['D'=>'Days','W'=>'Weeks','M'=>'Months','Y'=>'Years'];$s['interval_unit']=$map[substr($data,3)]??'Days';$s['stage']='rec_start';}
        elseif($stage==='rec_start'&&strpos($data,'sd:')===0){$v=substr($data,3);if($v==='t'){$s['start_date']=current_time('Y-m-d');$s['stage']='rec_end';}elseif($v==='m'){$s['start_date']=date('Y-m-d',strtotime(current_time('Y-m-d').' +1 day'));$s['stage']='rec_end';}else{$s['stage']='await_start_date';$this->telegram_put_state($chat_id,$user_id,$s);if($id)$this->telegram_answer_callback($id);$this->telegram_prompt($chat_id,$message_id,'Enter Start Date as DD/MM/YYYY or YYYY-MM-DD.');return;}}
        elseif($stage==='rec_end'&&strpos($data,'ed:')===0){if($data==='ed:n'){$s['end_date']='';$s['stage']='rec_time';}else{$s['stage']='await_end_date';$this->telegram_put_state($chat_id,$user_id,$s);if($id)$this->telegram_answer_callback($id);$this->telegram_prompt($chat_id,$message_id,'Enter End Date as DD/MM/YYYY or YYYY-MM-DD.');return;}}
        elseif($stage==='rec_time'&&strpos($data,'rt:')===0){if($data==='rt:n'){$s['due_time']='';$s['stage']='rec_overdue';}else{$s['stage']='await_rec_time';$this->telegram_put_state($chat_id,$user_id,$s);if($id)$this->telegram_answer_callback($id);$this->telegram_prompt($chat_id,$message_id,'Enter Due Time for each occurrence, for example 17:30 or 5:30 PM.');return;}}
        elseif($stage==='rec_overdue'&&strpos($data,'ov:')===0){$s['overdue_policy']=$data==='ov:k'?'keep':'until_next';$s['stage']='reminder';}
        elseif($stage==='reminder'&&strpos($data,'r:')===0){if($data==='r:n'){$s['reminder_enabled']=0;$s['stage']='confirm';}else{$s['reminder_enabled']=1;$s['stage']='await_reminder_value';$this->telegram_put_state($chat_id,$user_id,$s);if($id)$this->telegram_answer_callback($id);$this->telegram_prompt($chat_id,$message_id,'Enter reminder number, for example 30, 2, or 1.');return;}}
        elseif($stage==='reminder_unit'&&strpos($data,'ru:')===0){$map=['M'=>'Minutes','H'=>'Hours','D'=>'Days','W'=>'Weeks'];$unit=$map[substr($data,3)]??'Hours';if($unit==='Minutes'&&empty($s['due_time'])){$unit='Hours';}$s['reminder_unit']=$unit;$s['stage']='confirm';}
        elseif($stage==='confirm'&&$data==='c:create'){ $r=$this->telegram_create_task($s); if($id)$this->telegram_answer_callback($id,!empty($r['ok'])?'Task created':'Could not create'); if(!empty($r['ok'])){$this->telegram_clear_state($chat_id,$user_id);$rows=[];if(!empty($r['url']))$rows[]=[['text'=>'View Task','url'=>$r['url']]];$this->telegram_prompt($chat_id,$message_id,"✅ Task Created Successfully\n\n".($s['task_name']??''),$rows);}else{$this->telegram_prompt($chat_id,$message_id,'Could not create task: '.($r['message']??'Unknown error'),[[$this->telegram_btn('Cancel','x:cancel')]]);}return; }
        else { if($id)$this->telegram_answer_callback($id,'Please use the latest buttons.'); return; }
        $this->telegram_put_state($chat_id,$user_id,$s); if($id)$this->telegram_answer_callback($id); $this->telegram_show_stage($s,$chat_id,$message_id);
    }

    public function telegram_webhook($request){
        if(!get_option('bb_telegram_enabled',0)) return new WP_REST_Response(['ok'=>true,'disabled'=>true],200);
        $secret=(string)get_option('bb_telegram_webhook_secret',''); $header=(string)$request->get_header('x-telegram-bot-api-secret-token'); if($secret && !hash_equals($secret,$header)) return new WP_REST_Response(['ok'=>false],403);
        $u=$request->get_json_params(); if(!is_array($u))return new WP_REST_Response(['ok'=>true],200); if(!empty($u['callback_query']))$this->telegram_handle_callback($u['callback_query']); elseif(!empty($u['message']))$this->telegram_handle_message($u['message']); return new WP_REST_Response(['ok'=>true],200);
    }

    public function ajax_save_branding_settings(){
        $this->verify(); $this->require_manage();
        if(!empty($_POST['remove_logo'])){
            $this->delete_brand_logo_id();
            wp_send_json_success(['logoUrl'=>'']);
        }
        if(empty($_FILES['logo_file']) || empty($_FILES['logo_file']['name'])) wp_send_json_error(['message'=>'Choose a logo image to upload.'],422);
        if(!empty($_FILES['logo_file']['size']) && (int)$_FILES['logo_file']['size'] > 5*1024*1024) wp_send_json_error(['message'=>'Logo file must be 5 MB or smaller.'],422);
        $check=wp_check_filetype_and_ext($_FILES['logo_file']['tmp_name'],$_FILES['logo_file']['name']);
        $mime=(string)($check['type']??'');
        if(!in_array($mime,['image/png','image/jpeg','image/webp'],true)) wp_send_json_error(['message'=>'Use a PNG, JPG/JPEG or WEBP logo.'],422);
        $upload=$this->upload_brand_logo('logo_file');
        if(is_wp_error($upload)) wp_send_json_error(['message'=>$upload->get_error_message()],422);
        wp_send_json_success(['logoUrl'=>(string)($upload['url']??'')]);
    }

    public function ajax_save_ai_settings(){
        $this->verify(); $this->require_manage();
        update_option('bb_ai_enabled',!empty($_POST['enabled'])?1:0);
        $provider=sanitize_key($_POST['provider']??'openai'); if(!in_array($provider,['openai','gemini'],true)) $provider='openai';
        update_option('bb_ai_provider',$provider);
        $model=sanitize_text_field($_POST['model']??''); if(!$model) $model=$provider==='gemini'?'gemini-3.7-flash':'gpt-5'; update_option('bb_ai_model',$model);
        $max=max(1,min(25,absint($_POST['max_file_mb']??10))); update_option('bb_ai_max_file_mb',$max);
        $key=trim((string)wp_unslash($_POST['api_key']??'')); if($key!=='') update_option('bb_ai_api_key',$key,false);
        wp_send_json_success();
    }

    private function ai_extract_office_text($path,$ext){
        if(!class_exists('ZipArchive')) return '';
        $zip=new ZipArchive(); if($zip->open($path)!==true) return '';
        $text='';
        if($ext==='docx'){
            $xml=$zip->getFromName('word/document.xml');
            if($xml!==false){ $xml=preg_replace('/<w:tab[^>]*\/>/','\t',$xml); $xml=preg_replace('/<\/w:p>/','\n',$xml); $text=html_entity_decode(strip_tags($xml),ENT_QUOTES|ENT_XML1,'UTF-8'); }
        }elseif($ext==='xlsx'){
            $shared=[]; $sx=$zip->getFromName('xl/sharedStrings.xml');
            if($sx!==false){ $dom=@simplexml_load_string($sx); if($dom) foreach($dom->si as $si) $shared[]=trim((string)$si->t ?: implode('',array_map('strval',iterator_to_array($si->r->t??[])))); }
            for($n=1;$n<=20;$n++){
                $xml=$zip->getFromName("xl/worksheets/sheet{$n}.xml"); if($xml===false) continue;
                $dom=@simplexml_load_string($xml); if(!$dom) continue;
                foreach($dom->sheetData->row as $row){ $vals=[]; foreach($row->c as $c){ $v=(string)$c->v; if((string)$c['t']==='s' && isset($shared[(int)$v])) $v=$shared[(int)$v]; $vals[]=$v; } $text.=implode("\t",$vals)."\n"; }
            }
        }
        $zip->close(); return trim($text);
    }

    private function ai_uploaded_context(){
        if(empty($_FILES['source_file']) || empty($_FILES['source_file']['tmp_name'])) return null;
        $f=$_FILES['source_file']; if(!empty($f['error'])) return new WP_Error('upload_error','Could not read the uploaded file.');
        $max=max(1,min(25,(int)get_option('bb_ai_max_file_mb',10)))*MB_IN_BYTES; if((int)$f['size']>$max) return new WP_Error('too_large','File exceeds the AI upload size limit.');
        $name=sanitize_file_name($f['name']); $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
        $allowed=['jpg','jpeg','png','webp','pdf','docx','xlsx','csv','txt']; if(!in_array($ext,$allowed,true)) return new WP_Error('bad_type','Supported files: JPG, PNG, WEBP, PDF, DOCX, XLSX, CSV and TXT.');
        $mime=wp_check_filetype($name)['type']?:($f['type']??'application/octet-stream'); $path=$f['tmp_name'];
        if(in_array($ext,['txt','csv'],true)){ $text=file_get_contents($path); return ['kind'=>'text','name'=>$name,'mime'=>$mime,'text'=>mb_substr((string)$text,0,200000)]; }
        if(in_array($ext,['docx','xlsx'],true)){ $text=$this->ai_extract_office_text($path,$ext); if(!$text) return new WP_Error('extract_failed','Could not extract readable text from this file.'); return ['kind'=>'text','name'=>$name,'mime'=>'text/plain','text'=>mb_substr($text,0,200000)]; }
        $bytes=file_get_contents($path); if($bytes===false) return new WP_Error('read_failed','Could not read uploaded file.');
        return ['kind'=>in_array($ext,['jpg','jpeg','png','webp'],true)?'image':'file','name'=>$name,'mime'=>$mime,'base64'=>base64_encode($bytes)];
    }

    private function ai_builder_instruction(){
        global $wpdb; $names=$wpdb->get_col("SELECT name FROM {$this->tables['employees']} WHERE status='active' ORDER BY name");
        return "You are Workboard AI Workspace Builder. Convert the user's instruction and optional source document into a practical Workboard draft. Return JSON only, no markdown. Do not invent assignees, due dates, recurrence, priority or labels when they are not stated or reasonably implied. Use null/empty values when unknown. Existing users: ".implode(', ',array_map('sanitize_text_field',$names)).". If a named assignee exactly matches one of these users, use that exact name; otherwise preserve the requested name and add a warning. JSON shape: {\"workspace_name\":\"...\",\"description\":\"...\",\"warnings\":[\"...\"],\"work_areas\":[{\"name\":\"...\",\"description\":\"...\",\"tasks\":[{\"name\":\"...\",\"description\":\"...\",\"priority\":null|\"Urgent\"|\"High\"|\"Medium\"|\"Low\",\"assignee\":null|\"Name\",\"due_date\":null|\"YYYY-MM-DD\",\"task_type\":\"One Time\"|\"Recurring\",\"frequency\":null|\"Daily\"|\"Weekly\"|\"Fortnightly\"|\"Monthly\"|\"Quarterly\"|\"Yearly\",\"start_date\":null|\"YYYY-MM-DD\",\"labels\":[],\"subtasks\":[\"...\"]}]}]}. Keep the hierarchy concise and operational. Do not create empty work areas.";
    }

    private function ai_parse_json_text($text){
        $text=trim((string)$text); $text=preg_replace('/^```(?:json)?\s*/i','',$text); $text=preg_replace('/\s*```$/','',$text);
        $start=strpos($text,'{'); $end=strrpos($text,'}'); if($start!==false&&$end!==false&&$end>$start) $text=substr($text,$start,$end-$start+1);
        $data=json_decode($text,true); return is_array($data)?$data:null;
    }

    private function ai_sanitize_draft($d){
        if(!is_array($d)) return null; $out=['workspace_name'=>sanitize_text_field($d['workspace_name']??''),'description'=>sanitize_textarea_field($d['description']??''),'warnings'=>[],'work_areas'=>[]];
        foreach((array)($d['warnings']??[]) as $w){$w=sanitize_text_field($w);if($w)$out['warnings'][]=$w;}
        foreach((array)($d['work_areas']??[]) as $a){ $an=sanitize_text_field($a['name']??''); if(!$an)continue; $ao=['name'=>$an,'description'=>sanitize_textarea_field($a['description']??''),'tasks'=>[]];
            foreach((array)($a['tasks']??[]) as $t){ $tn=sanitize_text_field($t['name']??''); if(!$tn)continue; $type=(($t['task_type']??'')==='Recurring')?'Recurring':'One Time'; $freq=sanitize_text_field($t['frequency']??''); $validf=['Daily','Weekly','Fortnightly','Monthly','Quarterly','Yearly']; if(!in_array($freq,$validf,true))$freq=''; $pri=$this->normalize_priority($t['priority']??'');
                $ao['tasks'][]=['name'=>$tn,'description'=>sanitize_textarea_field($t['description']??''),'priority'=>$pri?:'','assignee'=>sanitize_text_field($t['assignee']??''),'due_date'=>preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($t['due_date']??''))?$t['due_date']:'','task_type'=>$type,'frequency'=>$type==='Recurring'?($freq?:'Monthly'):'','start_date'=>preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($t['start_date']??''))?$t['start_date']:'','labels'=>array_values(array_filter(array_map('sanitize_text_field',(array)($t['labels']??[])))),'subtasks'=>array_values(array_filter(array_map('sanitize_text_field',(array)($t['subtasks']??[]))))];
            } $out['work_areas'][]=$ao;
        } return $out['workspace_name']&&$out['work_areas']?$out:null;
    }

    private function ai_provider_error_message($provider,$code,$body){
        $j=json_decode((string)$body,true); $msg='';
        if(is_array($j)){
            if(!empty($j['error']['message'])) $msg=(string)$j['error']['message'];
            elseif(!empty($j['message'])) $msg=(string)$j['message'];
            elseif(!empty($j['error']) && is_string($j['error'])) $msg=(string)$j['error'];
        }
        if(!$msg){ $plain=trim(wp_strip_all_tags((string)$body)); if($plain) $msg=mb_substr($plain,0,500); }
        if(!$msg) $msg=ucfirst($provider).' request failed.';
        return ucfirst($provider).' API error'.($code?' (HTTP '.$code.')':'').': '.$msg;
    }

    private function ai_call_openai($instruction,$prompt,$ctx){
        $key=trim((string)get_option('bb_ai_api_key','')); $model=trim((string)get_option('bb_ai_model','gpt-5.6')); if(!$model)$model='gpt-5.6';
        $content=[['type'=>'input_text','text'=>$prompt?:'Create a Workboard workspace from the attached source.']];
        if($ctx){
            if($ctx['kind']==='text') $content[]=['type'=>'input_text','text'=>"SOURCE FILE: {$ctx['name']}\n{$ctx['text']}"];
            elseif($ctx['kind']==='image') $content[]=['type'=>'input_image','image_url'=>'data:'.$ctx['mime'].';base64,'.$ctx['base64'],'detail'=>'auto'];
            else $content[]=['type'=>'input_file','filename'=>$ctx['name'],'file_data'=>'data:'.$ctx['mime'].';base64,'.$ctx['base64']];
        }
        // JSON mode keeps the draft machine-readable while remaining compatible with the Responses API.
        $body=['model'=>$model,'input'=>[
            ['role'=>'developer','content'=>[['type'=>'input_text','text'=>$instruction]]],
            ['role'=>'user','content'=>$content]
        ],'text'=>['format'=>['type'=>'json_object']]];
        $r=wp_remote_post('https://api.openai.com/v1/responses',['timeout'=>120,'headers'=>['Authorization'=>'Bearer '.$key,'Content-Type'=>'application/json'],'body'=>wp_json_encode($body)]);
        if(is_wp_error($r)) return new WP_Error('ai_transport','OpenAI connection failed: '.$r->get_error_message());
        $code=(int)wp_remote_retrieve_response_code($r); $body_text=(string)wp_remote_retrieve_body($r); $j=json_decode($body_text,true);
        if($code<200||$code>=300) return new WP_Error('ai_api',$this->ai_provider_error_message('OpenAI',$code,$body_text));
        if(!is_array($j)) return new WP_Error('ai_invalid_response','OpenAI returned an unreadable response.');
        if(!empty($j['error']['message'])) return new WP_Error('ai_api','OpenAI API error: '.sanitize_text_field($j['error']['message']));
        $text='';
        if(!empty($j['output_text']) && is_string($j['output_text'])) $text=$j['output_text'];
        if(!$text) foreach((array)($j['output']??[]) as $o) foreach((array)($o['content']??[]) as $c) if(isset($c['text'])&&is_string($c['text'])) $text.=$c['text'];
        return trim($text)!==''?$text:new WP_Error('ai_empty','OpenAI returned no usable Workspace draft.');
    }

    private function ai_call_gemini($instruction,$prompt,$ctx){
        $key=trim((string)get_option('bb_ai_api_key','')); $model=trim((string)get_option('bb_ai_model','gemini-3.7-flash')); if(!$model)$model='gemini-3.7-flash';
        $parts=[['text'=>$instruction."\n\nUSER REQUEST:\n".($prompt?:'Create a Workboard workspace from the attached source.')]];
        if($ctx){ if($ctx['kind']==='text')$parts[]=['text'=>"SOURCE FILE: {$ctx['name']}\n{$ctx['text']}"]; else $parts[]=['inlineData'=>['mimeType'=>$ctx['mime'],'data'=>$ctx['base64']]]; }
        $url='https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent?key='.rawurlencode($key);
        $body=['contents'=>[['role'=>'user','parts'=>$parts]],'generationConfig'=>['responseMimeType'=>'application/json','temperature'=>0.2]];
        $r=wp_remote_post($url,['timeout'=>120,'headers'=>['Content-Type'=>'application/json'],'body'=>wp_json_encode($body)]);
        if(is_wp_error($r))return new WP_Error('ai_transport','Gemini connection failed: '.$r->get_error_message());
        $code=(int)wp_remote_retrieve_response_code($r); $body_text=(string)wp_remote_retrieve_body($r); $j=json_decode($body_text,true);
        if($code<200||$code>=300)return new WP_Error('ai_api',$this->ai_provider_error_message('Gemini',$code,$body_text));
        if(!is_array($j))return new WP_Error('ai_invalid_response','Gemini returned an unreadable response.');
        $text=''; foreach((array)($j['candidates'][0]['content']['parts']??[]) as $p) if(isset($p['text'])&&is_string($p['text']))$text.=$p['text'];
        return trim($text)!==''?$text:new WP_Error('ai_empty','Gemini returned no usable Workspace draft.');
    }

    public function ajax_ai_simplify_task_text(){
        $this->verify();
        if(!(int)get_option('bb_ai_enabled',0)) wp_send_json_error(['message'=>'AI is disabled. Enable it in Settings → AI Settings.'],403);
        if(!get_option('bb_ai_api_key','')) wp_send_json_error(['message'=>'Add an AI API key in Settings → AI Settings.'],422);
        $field=sanitize_key($_POST['field']??'task_name');
        if(!in_array($field,['task_name','description'],true)) $field='task_name';
        $text=trim((string)wp_unslash($_POST['text']??''));
        if($text==='') wp_send_json_error(['message'=>'Paste or enter some text first.'],422);
        if(mb_strlen($text)>12000) wp_send_json_error(['message'=>'Please simplify a shorter block of text (maximum 12,000 characters).'],422);

        if($field==='task_name'){
            $instruction='You simplify rough business instructions into one clear Workboard task name. Preserve the intended action, owner/person names, quantities, dates, deadlines and important business terms. Remove greetings, filler and repetition. If the input is Hindi/Hinglish, return natural concise English while preserving the exact meaning. If it is already English, keep it English. Do not invent facts. Keep the result action-oriented and ideally under 120 characters, never over 220 characters. Return JSON only in this exact shape: {"text":"..."}.';
        }else{
            $instruction='You simplify rough business instructions into concise, clear Workboard task instructions. Preserve every important action, person name, quantity, date, deadline, condition and business term. Remove greetings, filler, repetition and conversational wording. If the input is Hindi/Hinglish, return clear professional English while preserving the exact meaning. If it is already English, keep it English. Do not invent facts or add advice. Use short sentences or compact lines only when helpful. Return JSON only in this exact shape: {"text":"..."}.';
        }
        $provider=(string)get_option('bb_ai_provider','openai');
        $raw=$provider==='gemini'?$this->ai_call_gemini($instruction,$text,null):$this->ai_call_openai($instruction,$text,null);
        if(is_wp_error($raw)) wp_send_json_error(['message'=>$raw->get_error_message(),'stage'=>'provider']);
        $data=$this->ai_parse_json_text($raw);
        $simplified=trim((string)($data['text']??''));
        if($simplified==='') wp_send_json_error(['message'=>'AI did not return simplified text. Please try again.','stage'=>'parse']);
        $simplified=$field==='task_name'?sanitize_text_field($simplified):sanitize_textarea_field($simplified);
        if($field==='task_name' && mb_strlen($simplified)>220) $simplified=mb_substr($simplified,0,220);
        wp_send_json_success(['text'=>$simplified,'provider'=>$provider,'model'=>(string)get_option('bb_ai_model','')]);
    }

    public function ajax_ai_generate_workspace(){
        $this->verify(); $this->require_manage(); if(!(int)get_option('bb_ai_enabled',0)) wp_send_json_error(['message'=>'AI Workspace Builder is disabled. Enable it in Settings → AI Settings.'],403); if(!get_option('bb_ai_api_key','')) wp_send_json_error(['message'=>'Add an AI API key in Settings → AI Settings.'],422);
        $prompt=sanitize_textarea_field(wp_unslash($_POST['prompt']??'')); $ctx=$this->ai_uploaded_context(); if(is_wp_error($ctx)) wp_send_json_error(['message'=>$ctx->get_error_message()],422); if(!$prompt&&!$ctx) wp_send_json_error(['message'=>'Enter a prompt or upload a file/image.'],422);
        $provider=(string)get_option('bb_ai_provider','openai'); $raw=$provider==='gemini'?$this->ai_call_gemini($this->ai_builder_instruction(),$prompt,$ctx):$this->ai_call_openai($this->ai_builder_instruction(),$prompt,$ctx); if(is_wp_error($raw))wp_send_json_error(['message'=>$raw->get_error_message(),'stage'=>'provider']);
        $draft=$this->ai_sanitize_draft($this->ai_parse_json_text($raw)); if(!$draft)wp_send_json_error(['message'=>'AI returned a response, but it was not a valid Workspace draft. Please try again or use a more specific prompt.','stage'=>'parse']);
        global $wpdb; $active=array_map('strval',$wpdb->get_col("SELECT name FROM {$this->tables['employees']} WHERE status='active'")); foreach($draft['work_areas'] as &$a) foreach($a['tasks'] as &$t) if($t['assignee']&&!in_array($t['assignee'],$active,true))$draft['warnings'][]='User not found: '.$t['assignee']; unset($a,$t); $draft['warnings']=array_values(array_unique($draft['warnings']));
        wp_send_json_success(['draft'=>$draft,'provider'=>$provider,'model'=>(string)get_option('bb_ai_model','')]);
    }

    public function ajax_ai_create_workspace(){
        $this->verify(); $this->require_manage(); global $wpdb; $raw=wp_unslash($_POST['draft']??''); $draft=$this->ai_sanitize_draft(json_decode($raw,true)); if(!$draft)wp_send_json_error(['message'=>'AI draft is invalid.'],422);
        $assignment_items=$this->assignment_items_from_post('assignments');
        if(!$this->assignment_items_have_valid_workspace_owner($assignment_items)) wp_send_json_error(['message'=>'Please select at least one Workspace Owner.'],422);
        $wpdb->query('START TRANSACTION');
        $ws=['name'=>$draft['workspace_name'],'description'=>$draft['description'],'status'=>'active','sort_order'=>$this->next_workspace_sort_order(),'color'=>'#4f46e5','slug'=>$this->unique_workspace_slug($draft['workspace_name'],0)]; if($wpdb->insert($this->tables['workspaces'],$ws)===false){$wpdb->query('ROLLBACK');wp_send_json_error(['message'=>'Could not create Workspace.'],500);} $wid=(int)$wpdb->insert_id; $task_count=0;
        $this->save_entity_assignments_internal('workspace',$wid,$assignment_items);
        foreach($draft['work_areas'] as $i=>$a){ if($wpdb->insert($this->tables['work_areas'],['name'=>$a['name'],'description'=>$a['description'],'head_employee_id'=>null,'status'=>'active','color'=>'#4f46e5'])===false){$wpdb->query('ROLLBACK');wp_send_json_error(['message'=>'Could not create Work Area: '.$a['name']],500);} $aid=(int)$wpdb->insert_id; $wpdb->insert($this->tables['workspace_areas'],['workspace_id'=>$wid,'work_area_id'=>$aid,'sort_order'=>$i]);
            foreach($a['tasks'] as $t){ $eid=0;if($t['assignee']){$ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->tables['employees']} WHERE status='active' AND name=%s",$t['assignee']));if(count($ids)===1)$eid=(int)$ids[0];} $rec=$t['task_type']==='Recurring'; $today=current_time('Y-m-d'); $data=['task_name'=>$t['name'],'workspace_id'=>$wid,'work_area_id'=>$aid,'assignee_id'=>$eid?:null,'priority'=>$this->normalize_priority($t['priority']),'task_type'=>$rec?'Recurring':'One Time','is_template'=>$rec?1:0,'frequency'=>$rec?($t['frequency']?:'Monthly'):'None','interval_value'=>1,'interval_unit'=>'Days','start_date'=>$rec?($t['start_date']?:($t['due_date']?:$today)):null,'end_date'=>null,'due_date'=>$rec?null:($t['due_date']?:null),'due_time'=>null,'occurrence_date'=>$rec?null:($t['due_date']?:null),'description'=>$t['description'],'labels'=>implode(', ',$t['labels']),'estimated_minutes'=>0,'notify_enabled'=>1,'recurrence_status'=>'active','overdue_policy'=>'until_next','status'=>$rec?'Template':'Pending','completed_at'=>null,'completion_result'=>'','slug'=>$this->unique_task_slug($t['name'],0),'updated_at'=>current_time('mysql')];
                if($wpdb->insert($this->tables['tasks'],$data)===false){$wpdb->query('ROLLBACK');wp_send_json_error(['message'=>'Could not create task: '.$t['name']],500);} $tid=(int)$wpdb->insert_id; $task_count++; if($eid)$this->save_entity_assignments_internal('task',$tid,[['employee_id'=>$eid,'relation_type'=>'assignee','rights'=>'update']]); foreach($t['subtasks'] as $si=>$st)$wpdb->insert($this->tables['checklists'],['task_id'=>$tid,'item_text'=>$st,'sort_order'=>$si,'is_done'=>0]);
            }
        }
        $wpdb->query('COMMIT'); $this->log_activity('ai_workspace_created',$draft['workspace_name'],$wid); wp_send_json_success(['workspace_id'=>$wid,'workspace_name'=>$draft['workspace_name'],'areas'=>count($draft['work_areas']),'tasks'=>$task_count]);
    }


    private function ai_workspace_snapshot($workspace_id){
        global $wpdb; $workspace_id=absint($workspace_id);
        $ws=$wpdb->get_row($wpdb->prepare("SELECT id,name,description,status FROM {$this->tables['workspaces']} WHERE id=%d",$workspace_id),ARRAY_A);
        if(!$ws) return null;
        $areas=$wpdb->get_results($wpdb->prepare("SELECT a.id,a.name,a.description,wa.sort_order FROM {$this->tables['workspace_areas']} wa JOIN {$this->tables['work_areas']} a ON a.id=wa.work_area_id WHERE wa.workspace_id=%d AND a.status NOT IN ('trash','archived') ORDER BY wa.sort_order,a.id",$workspace_id),ARRAY_A);
        $out=['id'=>(int)$ws['id'],'name'=>$ws['name'],'description'=>$ws['description'],'status'=>$ws['status'],'work_areas'=>[]];
        foreach($areas as $a){
            $ao=['id'=>(int)$a['id'],'name'=>$a['name'],'description'=>$a['description'],'tasks'=>[]];
            $tasks=$wpdb->get_results($wpdb->prepare("SELECT t.id,t.task_name,t.description,t.priority,t.task_type,t.frequency,t.start_date,t.due_date,t.labels,t.status,t.is_template,e.name assignee FROM {$this->tables['tasks']} t LEFT JOIN {$this->tables['employees']} e ON e.id=t.assignee_id WHERE t.workspace_id=%d AND t.work_area_id=%d AND t.archived_at IS NULL ORDER BY t.sort_order,t.id",$workspace_id,(int)$a['id']),ARRAY_A);
            foreach($tasks as $t){
                $subs=$wpdb->get_results($wpdb->prepare("SELECT id,item_text,is_done,sort_order FROM {$this->tables['checklists']} WHERE task_id=%d ORDER BY sort_order,id",(int)$t['id']),ARRAY_A);
                $ao['tasks'][]=['id'=>(int)$t['id'],'name'=>$t['task_name'],'description'=>$t['description'],'priority'=>$t['priority'],'assignee'=>$t['assignee']?:null,'task_type'=>$t['task_type'],'frequency'=>$t['frequency'],'start_date'=>$t['start_date'],'due_date'=>$t['due_date'],'labels'=>array_values(array_filter(array_map('trim',explode(',',(string)$t['labels'])))),'status'=>$t['status'],'subtasks'=>array_map(fn($c)=>['id'=>(int)$c['id'],'text'=>$c['item_text'],'done'=>(int)$c['is_done']],$subs)];
            }
            $out['work_areas'][]=$ao;
        }
        return $out;
    }

    private function ai_modifier_instruction($workspace_id,$snapshot){
        global $wpdb; $names=$wpdb->get_col("SELECT name FROM {$this->tables['employees']} WHERE status='active' ORDER BY name");
        $schema='{"summary":"...","warnings":["..."],"actions":[{"type":"update_workspace","fields":{"name":"...","description":"..."}},{"type":"add_work_area","name":"...","description":"...","tasks":[TASK...]},{"type":"update_work_area","id":123,"fields":{"name":"...","description":"..."}},{"type":"delete_work_area","id":123},{"type":"add_task","work_area_id":123,"task":TASK},{"type":"update_task","id":456,"fields":TASK_FIELDS},{"type":"delete_task","id":456},{"type":"add_subtask","task_id":456,"text":"..."},{"type":"update_subtask","id":789,"text":"..."},{"type":"delete_subtask","id":789}]}';
        return 'You are Workboard AI Workspace Modifier. Modify ONLY the supplied existing Workspace according to the user request and optional file/image. Return JSON only, no markdown. Preserve anything not explicitly requested to change. Never invent user names. Existing users: '.implode(', ',array_map('sanitize_text_field',$names)).'. Use the exact IDs from CURRENT WORKSPACE for updates/deletes. New items do not need IDs. Do not delete anything unless the user clearly asks to remove/delete it. JSON shape: '.$schema.'. TASK/TASK_FIELDS may contain name,description,priority (Urgent/High/Medium/Low),assignee,due_date YYYY-MM-DD or null,task_type One Time/Recurring,frequency Daily/Weekly/Fortnightly/Monthly/Quarterly/Yearly,start_date YYYY-MM-DD or null,labels array,subtasks array of strings. Return only actions that are actually needed. CURRENT WORKSPACE JSON: '.wp_json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }

    private function ai_sanitize_task_payload($t,$partial=false){
        if(!is_array($t)) return [];
        $o=[];
        $map=['name'=>'sanitize_text_field','description'=>'sanitize_textarea_field','assignee'=>'sanitize_text_field'];
        foreach($map as $k=>$fn) if(array_key_exists($k,$t)) $o[$k]=$fn($t[$k]);
        if(array_key_exists('priority',$t)){ $p=$this->normalize_priority($t['priority']??''); $o['priority']=$p?:''; }
        if(array_key_exists('due_date',$t)){ $v=(string)($t['due_date']??''); $o['due_date']=preg_match('/^\d{4}-\d{2}-\d{2}$/',$v)?$v:''; }
        if(array_key_exists('start_date',$t)){ $v=(string)($t['start_date']??''); $o['start_date']=preg_match('/^\d{4}-\d{2}-\d{2}$/',$v)?$v:''; }
        if(array_key_exists('task_type',$t)) $o['task_type']=(($t['task_type']??'')==='Recurring')?'Recurring':'One Time';
        if(array_key_exists('frequency',$t)){ $f=sanitize_text_field($t['frequency']??''); $valid=['Daily','Weekly','Fortnightly','Monthly','Quarterly','Yearly']; $o['frequency']=in_array($f,$valid,true)?$f:''; }
        if(array_key_exists('labels',$t)) $o['labels']=array_values(array_filter(array_map('sanitize_text_field',(array)$t['labels'])));
        if(array_key_exists('subtasks',$t)) $o['subtasks']=array_values(array_filter(array_map('sanitize_text_field',(array)$t['subtasks'])));
        if(!$partial && empty($o['name'])) return [];
        return $o;
    }

    private function ai_sanitize_change_plan($d,$workspace_id){
        if(!is_array($d)) return null; global $wpdb; $workspace_id=absint($workspace_id);
        $area_ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT work_area_id FROM {$this->tables['workspace_areas']} WHERE workspace_id=%d",$workspace_id)));
        $task_ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->tables['tasks']} WHERE workspace_id=%d",$workspace_id)));
        $sub_ids=$task_ids?array_map('intval',$wpdb->get_col("SELECT id FROM {$this->tables['checklists']} WHERE task_id IN (".implode(',',$task_ids).")")):[];
        $out=['summary'=>sanitize_text_field($d['summary']??''),'warnings'=>[],'actions'=>[]];
        foreach((array)($d['warnings']??[]) as $w){$w=sanitize_text_field($w);if($w)$out['warnings'][]=$w;}
        foreach((array)($d['actions']??[]) as $a){ if(!is_array($a))continue; $type=sanitize_key($a['type']??''); $x=['type'=>$type];
            if($type==='update_workspace'){
                $f=[]; if(isset($a['fields']['name']))$f['name']=sanitize_text_field($a['fields']['name']); if(isset($a['fields']['description']))$f['description']=sanitize_textarea_field($a['fields']['description']); if($f){$x['fields']=$f;$out['actions'][]=$x;}
            }elseif($type==='add_work_area'){
                $name=sanitize_text_field($a['name']??''); if(!$name)continue; $x['name']=$name;$x['description']=sanitize_textarea_field($a['description']??'');$x['tasks']=[];foreach((array)($a['tasks']??[]) as $t){$tt=$this->ai_sanitize_task_payload($t,false);if($tt)$x['tasks'][]=$tt;} $out['actions'][]=$x;
            }elseif($type==='update_work_area' || $type==='delete_work_area'){
                $id=absint($a['id']??0); if(!$id||!in_array($id,$area_ids,true))continue; $x['id']=$id; if($type==='update_work_area'){ $f=[];if(isset($a['fields']['name']))$f['name']=sanitize_text_field($a['fields']['name']);if(isset($a['fields']['description']))$f['description']=sanitize_textarea_field($a['fields']['description']);if(!$f)continue;$x['fields']=$f;} $out['actions'][]=$x;
            }elseif($type==='add_task'){
                $aid=absint($a['work_area_id']??0); if(!$aid||!in_array($aid,$area_ids,true))continue; $t=$this->ai_sanitize_task_payload($a['task']??[],false); if(!$t)continue;$x['work_area_id']=$aid;$x['task']=$t;$out['actions'][]=$x;
            }elseif($type==='update_task' || $type==='delete_task'){
                $id=absint($a['id']??0); if(!$id||!in_array($id,$task_ids,true))continue;$x['id']=$id;if($type==='update_task'){ $f=$this->ai_sanitize_task_payload($a['fields']??[],true);if(!$f)continue;$x['fields']=$f;} $out['actions'][]=$x;
            }elseif($type==='add_subtask'){
                $tid=absint($a['task_id']??0);$text=sanitize_text_field($a['text']??'');if(!$tid||!in_array($tid,$task_ids,true)||!$text)continue;$x['task_id']=$tid;$x['text']=$text;$out['actions'][]=$x;
            }elseif($type==='update_subtask' || $type==='delete_subtask'){
                $id=absint($a['id']??0);if(!$id||!in_array($id,$sub_ids,true))continue;$x['id']=$id;if($type==='update_subtask'){ $text=sanitize_text_field($a['text']??'');if(!$text)continue;$x['text']=$text;} $out['actions'][]=$x;
            }
        }
        return $out['actions']?$out:null;
    }

    public function ajax_ai_generate_workspace_changes(){
        $this->verify(); $this->require_manage(); global $wpdb; $wid=absint($_POST['workspace_id']??0);
        if(!$wid || !$this->workspace_permission($wid,'edit')) wp_send_json_error(['message'=>'You do not have permission to modify this Workspace.'],403);
        if(!(int)get_option('bb_ai_enabled',0)) wp_send_json_error(['message'=>'AI Workspace Builder is disabled.'],403); if(!get_option('bb_ai_api_key','')) wp_send_json_error(['message'=>'Add an AI API key in Settings → AI Settings.'],422);
        $snapshot=$this->ai_workspace_snapshot($wid); if(!$snapshot) wp_send_json_error(['message'=>'Workspace not found.'],404);
        $prompt=sanitize_textarea_field(wp_unslash($_POST['prompt']??'')); $ctx=$this->ai_uploaded_context(); if(is_wp_error($ctx))wp_send_json_error(['message'=>$ctx->get_error_message()],422); if(!$prompt&&!$ctx)wp_send_json_error(['message'=>'Describe the changes or upload a file/image.'],422);
        $provider=(string)get_option('bb_ai_provider','openai'); $instruction=$this->ai_modifier_instruction($wid,$snapshot); $raw=$provider==='gemini'?$this->ai_call_gemini($instruction,$prompt,$ctx):$this->ai_call_openai($instruction,$prompt,$ctx); if(is_wp_error($raw))wp_send_json_error(['message'=>$raw->get_error_message(),'stage'=>'provider']);
        $plan=$this->ai_sanitize_change_plan($this->ai_parse_json_text($raw),$wid); if(!$plan)wp_send_json_error(['message'=>'AI did not return any valid changes. Try a more specific instruction.','stage'=>'parse']);
        wp_send_json_success(['plan'=>$plan,'provider'=>$provider,'model'=>(string)get_option('bb_ai_model',''),'workspace'=>$snapshot['name']]);
    }

    private function ai_delete_task_record($tid){
        global $wpdb; $tid=absint($tid); if(!$tid)return;
        $check_ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->tables['checklists']} WHERE task_id=%d",$tid))); foreach($check_ids as $cid)$wpdb->delete($this->tables['entity_assignments'],['entity_type'=>'subtask','entity_id'=>$cid]);
        $wpdb->delete($this->tables['history'],['task_id'=>$tid]);$wpdb->delete($this->tables['score_archive'],['task_id'=>$tid]);$wpdb->delete($this->tables['subtask_progress'],['task_id'=>$tid]);$wpdb->delete($this->tables['checklists'],['task_id'=>$tid]);$wpdb->delete($this->tables['task_remarks'],['task_id'=>$tid]);$wpdb->delete($this->tables['entity_assignments'],['entity_type'=>'task','entity_id'=>$tid]);if(isset($this->tables['task_attachments']))$wpdb->delete($this->tables['task_attachments'],['task_id'=>$tid]);$wpdb->delete($this->tables['tasks'],['id'=>$tid]);
    }

    private function ai_insert_task($wid,$aid,$t){
        global $wpdb; $eid=0;if(!empty($t['assignee'])){$ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->tables['employees']} WHERE status='active' AND name=%s",$t['assignee']));if(count($ids)===1)$eid=(int)$ids[0];}
        $rec=($t['task_type']??'One Time')==='Recurring';$today=current_time('Y-m-d');$data=['task_name'=>$t['name'],'workspace_id'=>$wid,'work_area_id'=>$aid,'assignee_id'=>$eid?:null,'priority'=>$this->normalize_priority($t['priority']??'Medium'),'task_type'=>$rec?'Recurring':'One Time','is_template'=>$rec?1:0,'frequency'=>$rec?($t['frequency']??'Monthly'):'None','interval_value'=>1,'interval_unit'=>'Days','start_date'=>$rec?($t['start_date']?:($t['due_date']?:$today)):null,'due_date'=>$rec?null:($t['due_date']?:null),'occurrence_date'=>$rec?null:($t['due_date']?:null),'description'=>$t['description']??'','labels'=>implode(', ',(array)($t['labels']??[])),'notify_enabled'=>1,'recurrence_status'=>'active','overdue_policy'=>'until_next','status'=>$rec?'Template':'Pending','slug'=>$this->unique_task_slug($t['name'],0),'updated_at'=>current_time('mysql')];
        if($wpdb->insert($this->tables['tasks'],$data)===false)return 0;$tid=(int)$wpdb->insert_id;if($eid)$this->save_entity_assignments_internal('task',$tid,[['employee_id'=>$eid,'relation_type'=>'assignee','rights'=>'update']]);foreach((array)($t['subtasks']??[]) as $i=>$st)$wpdb->insert($this->tables['checklists'],['task_id'=>$tid,'item_text'=>$st,'sort_order'=>$i,'is_done'=>0]);return $tid;
    }

    public function ajax_ai_apply_workspace_changes(){
        $this->verify(); $this->require_manage(); global $wpdb; $wid=absint($_POST['workspace_id']??0); if(!$wid||!$this->workspace_permission($wid,'edit'))wp_send_json_error(['message'=>'You do not have permission to modify this Workspace.'],403);
        $plan=$this->ai_sanitize_change_plan(json_decode(wp_unslash($_POST['plan']??''),true),$wid); if(!$plan)wp_send_json_error(['message'=>'No valid AI changes selected.'],422);
        $wpdb->query('START TRANSACTION');$counts=['updated'=>0,'added'=>0,'deleted'=>0];
        foreach($plan['actions'] as $a){$type=$a['type'];
            if($type==='update_workspace'){ $f=$a['fields']; if(isset($f['name'])){$f['slug']=$this->unique_workspace_slug($f['name'],$wid);} if($wpdb->update($this->tables['workspaces'],$f,['id'=>$wid])!==false)$counts['updated']++;
            }elseif($type==='add_work_area'){ $wpdb->insert($this->tables['work_areas'],['name'=>$a['name'],'description'=>$a['description'],'status'=>'active','color'=>'#4f46e5']);$aid=(int)$wpdb->insert_id;$sort=(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(sort_order),-1)+1 FROM {$this->tables['workspace_areas']} WHERE workspace_id=%d",$wid));$wpdb->insert($this->tables['workspace_areas'],['workspace_id'=>$wid,'work_area_id'=>$aid,'sort_order'=>$sort]);$counts['added']++;foreach($a['tasks'] as $t){if($this->ai_insert_task($wid,$aid,$t))$counts['added']++;}
            }elseif($type==='update_work_area'){ if($wpdb->update($this->tables['work_areas'],$a['fields'],['id'=>$a['id']])!==false)$counts['updated']++;
            }elseif($type==='delete_work_area'){ $wpdb->update($this->tables['work_areas'],['status'=>'trash'],['id'=>$a['id']]);$counts['deleted']++;
            }elseif($type==='add_task'){ if($this->ai_insert_task($wid,$a['work_area_id'],$a['task']))$counts['added']++;
            }elseif($type==='update_task'){ $t=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['tasks']} WHERE id=%d AND workspace_id=%d",$a['id'],$wid));if(!$t)continue;$f=$a['fields'];$data=[];if(isset($f['name'])){$data['task_name']=$f['name'];$data['slug']=$this->unique_task_slug($f['name'],$a['id']);}if(isset($f['description']))$data['description']=$f['description'];if(isset($f['priority']))$data['priority']=$this->normalize_priority($f['priority']);if(isset($f['labels']))$data['labels']=implode(', ',$f['labels']);if(isset($f['assignee'])){$eid=0;if($f['assignee']){$ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->tables['employees']} WHERE status='active' AND name=%s",$f['assignee']));if(count($ids)===1)$eid=(int)$ids[0];}$data['assignee_id']=$eid?:null;$this->save_entity_assignments_internal('task',$a['id'],$eid?[['employee_id'=>$eid,'relation_type'=>'assignee','rights'=>'update']]:[]);}if(isset($f['task_type'])){$rec=$f['task_type']==='Recurring';$data['task_type']=$rec?'Recurring':'One Time';$data['is_template']=$rec?1:0;$data['status']=$rec?'Template':(($t->status==='Template')?'Pending':$t->status);$data['frequency']=$rec?($f['frequency']??($t->frequency?:'Monthly')):'None';$data['start_date']=$rec?($f['start_date']??($t->start_date?:current_time('Y-m-d'))):null;$data['due_date']=$rec?null:($f['due_date']??$t->due_date);}else{if(isset($f['frequency']))$data['frequency']=$f['frequency'];if(isset($f['start_date']))$data['start_date']=$f['start_date']?:null;if(isset($f['due_date']))$data['due_date']=$f['due_date']?:null;}if($data){$data['updated_at']=current_time('mysql');$wpdb->update($this->tables['tasks'],$data,['id'=>$a['id']]);}$counts['updated']++;
            }elseif($type==='delete_task'){ $this->ai_delete_task_record($a['id']);$counts['deleted']++;
            }elseif($type==='add_subtask'){ $sort=(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(sort_order),-1)+1 FROM {$this->tables['checklists']} WHERE task_id=%d",$a['task_id']));$wpdb->insert($this->tables['checklists'],['task_id'=>$a['task_id'],'item_text'=>$a['text'],'sort_order'=>$sort,'is_done'=>0]);$counts['added']++;
            }elseif($type==='update_subtask'){ $wpdb->update($this->tables['checklists'],['item_text'=>$a['text']],['id'=>$a['id']]);$counts['updated']++;
            }elseif($type==='delete_subtask'){ $wpdb->delete($this->tables['entity_assignments'],['entity_type'=>'subtask','entity_id'=>$a['id']]);$wpdb->delete($this->tables['checklists'],['id'=>$a['id']]);$counts['deleted']++; }
        }
        $wpdb->query('COMMIT');$this->log_activity('ai_workspace_modified','AI changes applied',$wid);wp_send_json_success(['message'=>'Workspace updated with AI','counts'=>$counts]);
    }

    private function report_filters_from_request(){
        $today=current_time('Y-m-d');
        $from=sanitize_text_field($_REQUEST['from']??date('Y-m-01',strtotime($today)));
        $to=sanitize_text_field($_REQUEST['to']??$today);
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)) $from=date('Y-m-01',strtotime($today));
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)) $to=$today;
        if($from>$to){ $x=$from;$from=$to;$to=$x; }
        return ['from'=>$from,'to'=>$to,'workspace_id'=>absint($_REQUEST['workspace_id']??0),'work_area_id'=>absint($_REQUEST['work_area_id']??0),'employee_id'=>absint($_REQUEST['employee_id']??0),'result'=>sanitize_text_field($_REQUEST['result']??'')];
    }

    private function report_dataset($f,$limit=1000){
        global $wpdb; $allowed=$this->allowed_workspace_ids();
        $rows=[];
        $hist=$wpdb->get_results($wpdb->prepare("SELECT h.*,t.task_name,w.name workspace_name,a.name work_area_name,e.name employee_name
            FROM {$this->tables['history']} h
            LEFT JOIN {$this->tables['tasks']} t ON t.id=h.task_id
            LEFT JOIN {$this->tables['workspaces']} w ON w.id=h.workspace_id
            LEFT JOIN {$this->tables['work_areas']} a ON a.id=h.work_area_id
            LEFT JOIN {$this->tables['employees']} e ON e.id=h.assignee_id
            WHERE h.due_date BETWEEN %s AND %s ORDER BY h.due_date DESC,h.id DESC LIMIT %d",$f['from'],$f['to'],max(1,(int)$limit)));
        foreach($hist as $r){
            if($allowed!==null && !in_array((int)$r->workspace_id,$allowed,true)) continue;
            if($f['workspace_id'] && (int)$r->workspace_id!==$f['workspace_id']) continue;
            if($f['work_area_id'] && (int)$r->work_area_id!==$f['work_area_id']) continue;
            if($f['employee_id'] && (int)$r->assignee_id!==$f['employee_id']) continue;
            if($f['result'] && strcasecmp($r->result,$f['result'])!==0) continue;
            $rows[]=['type'=>'Recurring','task_id'=>(int)$r->task_id,'task_name'=>$r->task_name?:'Recurring Task','workspace_id'=>(int)$r->workspace_id,'workspace'=>$r->workspace_name?:'','work_area_id'=>(int)$r->work_area_id,'work_area'=>$r->work_area_name?:'','employee_id'=>(int)$r->assignee_id,'employee'=>$r->employee_name?:'Unassigned','due_date'=>$r->due_date,'due_time'=>$r->due_time,'status'=>$r->status,'result'=>$r->result?:($r->status==='Overdue'?'Missed':''),'completed_at'=>$r->completed_at,'score_points'=>(int)$r->score_points];
        }
        $one=$wpdb->get_results($wpdb->prepare("SELECT t.*,w.name workspace_name,a.name work_area_name,e.name employee_name
            FROM {$this->tables['tasks']} t
            LEFT JOIN {$this->tables['workspaces']} w ON w.id=t.workspace_id
            LEFT JOIN {$this->tables['work_areas']} a ON a.id=t.work_area_id
            LEFT JOIN {$this->tables['employees']} e ON e.id=t.assignee_id
            WHERE t.is_template=0 AND t.template_id IS NULL AND t.archived_at IS NULL AND (w.status IS NULL OR w.status NOT IN ('trash','archived')) AND t.due_date BETWEEN %s AND %s ORDER BY t.due_date DESC,t.id DESC LIMIT %d",$f['from'],$f['to'],max(1,(int)$limit)));
        foreach($one as $r){
            if($allowed!==null && !in_array((int)$r->workspace_id,$allowed,true)) continue;
            if($f['workspace_id'] && (int)$r->workspace_id!==$f['workspace_id']) continue;
            if($f['work_area_id'] && (int)$r->work_area_id!==$f['work_area_id']) continue;
            if($f['employee_id'] && (int)$r->assignee_id!==$f['employee_id']) continue;
            $result=$r->status==='Completed'?($r->completion_result?:'On Time'):($r->status==='Overdue'?'Missed':'Pending');
            if($f['result'] && strcasecmp($result,$f['result'])!==0) continue;
            $points=$result==='Late'?60:(in_array($result,['Early','On Time'],true)?100:0);
            $rows[]=['type'=>'One Time','task_id'=>(int)$r->id,'task_name'=>$r->task_name,'workspace_id'=>(int)$r->workspace_id,'workspace'=>$r->workspace_name?:'','work_area_id'=>(int)$r->work_area_id,'work_area'=>$r->work_area_name?:'','employee_id'=>(int)$r->assignee_id,'employee'=>$r->employee_name?:'Unassigned','due_date'=>$r->due_date,'due_time'=>$r->due_time,'status'=>$r->status,'result'=>$result,'completed_at'=>$r->completed_at,'score_points'=>$points];
        }
        usort($rows,fn($a,$b)=>strcmp(($b['due_date']??'').' '.($b['due_time']??''),($a['due_date']??'').' '.($a['due_time']??'')));
        if(count($rows)>$limit) $rows=array_slice($rows,0,$limit);
        $summary=['due'=>0,'timely'=>0,'late'=>0,'missed'=>0,'pending'=>0,'points'=>0,'score'=>0];
        foreach($rows as $r){
            if($r['result']==='Pending'){ $summary['pending']++; continue; }
            $summary['due']++; $summary['points']+=(int)$r['score_points'];
            if(in_array($r['result'],['Early','On Time'],true)) $summary['timely']++; elseif($r['result']==='Late') $summary['late']++; elseif($r['result']==='Missed') $summary['missed']++;
        }
        $summary['score']=$summary['due']?round($summary['points']/$summary['due'],1):0;
        return ['rows'=>$rows,'summary'=>$summary];
    }

    private function archived_report_summary($f){
        global $wpdb; $where=['period_month <= %s','DATE_ADD(period_month, INTERVAL 1 MONTH) > %s']; $args=[substr($f['to'],0,7).'-01',$f['from']];
        foreach(['workspace_id','work_area_id','employee_id'] as $key){ if(!empty($f[$key])){ $col=$key==='employee_id'?'assignee_id':$key; $where[]="$col=%d"; $args[]=$f[$key]; } }
        $sql="SELECT SUM(due_count) due,SUM(early_count+on_time_count) timely,SUM(late_count) late,SUM(missed_count) missed,SUM(skipped_count) skipped,SUM(total_points) points FROM {$this->tables['score_archive']} WHERE ".implode(' AND ',$where);
        $r=$wpdb->get_row($wpdb->prepare($sql,...$args));
        $due=(int)($r->due??0); return ['due'=>$due,'timely'=>(int)($r->timely??0),'late'=>(int)($r->late??0),'missed'=>(int)($r->missed??0),'skipped'=>(int)($r->skipped??0),'points'=>(int)($r->points??0),'score'=>$due?round(((int)$r->points)/$due,1):0];
    }

    // v4.18.158: Daily Recurring Tasks Scoreboard — per User, per date: occurrences due vs completed.
    public function ajax_get_daily_recurring_scoreboard(){
        $this->verify(); global $wpdb;
        $today=current_time('Y-m-d');
        $month=sanitize_text_field($_POST['month']??'');
        if(!preg_match('/^\d{4}-\d{2}$/',$month)) $month=substr($today,0,7);
        $from=$month.'-01'; $to=date('Y-m-t',strtotime($from));
        if($to>$today) $to=$today;
        $privileged=$this->is_privileged_user();
        $employee_id=absint($_POST['employee_id']??0);
        if(!$privileged){ $me=$this->current_employee(); $employee_id=$me?(int)$me->id:-1; }
        $allowed=$this->allowed_workspace_ids();
        $out=[];
        if($from<=$to && $employee_id!==-1){
            $daily="t.is_template=1 AND t.task_type='Recurring' AND COALESCE(NULLIF(t.frequency,''),'Daily')='Daily'";
            $emp_sql=$employee_id?$wpdb->prepare(' AND h.assignee_id=%d',$employee_id):'';
            $rows=$wpdb->get_results($wpdb->prepare("SELECT h.assignee_id,h.due_date,h.occurrence_date,h.workspace_id,h.status,h.due_time,h.result,h.completed_at,t.id task_id,t.task_name,t.goal_target,t.goal_unit,w.name workspace_name,a.name work_area_name FROM {$this->tables['history']} h JOIN {$this->tables['tasks']} t ON t.id=h.task_id LEFT JOIN {$this->tables['workspaces']} w ON w.id=h.workspace_id LEFT JOIN {$this->tables['work_areas']} a ON a.id=h.work_area_id WHERE {$daily} AND h.assignee_id IS NOT NULL AND h.due_date BETWEEN %s AND %s AND (w.status IS NULL OR w.status NOT IN ('trash')) AND (a.status IS NULL OR a.status NOT IN ('trash')) AND t.archived_at IS NULL{$emp_sql}",$from,$to));
            // v4.18.163: update remarks per task occurrence (occurrence_key = occurrence date).
            $remarks=[];
            $tids=array_values(array_unique(array_map(fn($x)=>(int)$x->task_id,$rows)));
            if($from<=$today && $today<=$to){ foreach($wpdb->get_col("SELECT t.id FROM {$this->tables['tasks']} t WHERE {$daily} AND t.assignee_id IS NOT NULL") as $x) $tids[]=(int)$x; $tids=array_values(array_unique($tids)); }
            if($tids){
                $in=implode(',',array_map('intval',$tids));
                $rm=$wpdb->get_results($wpdb->prepare("SELECT task_id,occurrence_key,remark,created_at FROM {$this->tables['task_remarks']} WHERE task_id IN ({$in}) AND occurrence_key BETWEEN %s AND %s ORDER BY created_at,id",$from,$to));
                foreach($rm as $x){ $remarks[(int)$x->task_id.'|'.$x->occurrence_key][]=['remark'=>(string)$x->remark,'at'=>$x->created_at?$this->activity_time_ist($x->created_at):'']; }
            }
            $goal_map=[]; $goal_day=[];
            if($tids && in_array((string)get_option('bb_goal_schema',''),['1','2','3'],true)){
                $in=implode(',',array_map('intval',$tids));
                foreach($wpdb->get_results($wpdb->prepare("SELECT task_id,occurrence_key,SUM(amount) total,SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(goal_target,0) ORDER BY id DESC),',',1) day_target,SUBSTRING_INDEX(GROUP_CONCAT(IF(goal_target>0,goal_unit,NULL) ORDER BY id DESC SEPARATOR '\x1f'),'\x1f',1) day_unit FROM {$this->tables['goal_progress']} WHERE task_id IN ({$in}) AND occurrence_key BETWEEN %s AND %s GROUP BY task_id,occurrence_key",$from,$to)) as $x){ $goal_map[(int)$x->task_id.'|'.$x->occurrence_key]=(int)$x->total; $goal_day[(int)$x->task_id.'|'.$x->occurrence_key]=[(int)$x->day_target,(string)$x->day_unit]; }
            }
            $seen_today=[];
            foreach($rows as $r){
                $rk=$remarks[(int)$r->task_id.'|'.($r->occurrence_date?:$r->due_date)]??[];
                if($allowed!==null && !in_array((int)$r->workspace_id,$allowed,true)) continue;
                $k=(int)$r->assignee_id.'|'.$r->due_date;
                if(!isset($out[$k])) $out[$k]=['employee_id'=>(int)$r->assignee_id,'date'=>$r->due_date,'due'=>0,'done'=>0,'not_done'=>[],'done_list'=>[]];
                $out[$k]['due']++;
                $gkey=(int)$r->task_id.'|'.($r->occurrence_date?:$r->due_date); $gday=$goal_day[$gkey]??[0,''];
                $gt=$gday[0]>0?$gday[0]:(int)($r->goal_target??0); $gunit=$gday[0]>0?$gday[1]:(string)($r->goal_unit??''); $gd=$gt?($goal_map[$gkey]??0):0;
                if($r->status!=='Completed' && $gt>0 && $gd>0){ $out[$k]['done']+=min(1,$gd/$gt); }
                if($r->status==='Completed'){ $out[$k]['done']++; $out[$k]['done_list'][]=['task_id'=>(int)$r->task_id,'task_name'=>(string)($r->task_name?:'Recurring Task'),'workspace'=>(string)($r->workspace_name?:''),'work_area'=>(string)($r->work_area_name?:''),'due_time'=>(string)($r->due_time?:''),'status'=>(string)($r->result?:'Completed'),'completed_at'=>$r->completed_at?$this->activity_time_ist($r->completed_at):'','remarks'=>$rk,'goal_target'=>$gt,'goal_unit'=>$gunit,'goal_done'=>$gd]; }
                else $out[$k]['not_done'][]=['task_id'=>(int)$r->task_id,'task_name'=>(string)($r->task_name?:'Recurring Task'),'workspace'=>(string)($r->workspace_name?:''),'work_area'=>(string)($r->work_area_name?:''),'due_time'=>(string)($r->due_time?:''),'status'=>(string)($r->status?:'Pending'),'remarks'=>$rk,'goal_target'=>$gt,'goal_unit'=>$gunit,'goal_done'=>$gd];
            }
            // Today's occurrences that are not yet overdue have no history row yet: count them as due, not done.
            if($from<=$today && $today<=$to){
                $tpl_emp=$employee_id?$wpdb->prepare(' AND t.assignee_id=%d',$employee_id):'';
                $tpls=$wpdb->get_results("SELECT t.* FROM {$this->tables['tasks']} t LEFT JOIN {$this->tables['work_areas']} a ON a.id=t.work_area_id LEFT JOIN {$this->tables['workspaces']} w ON w.id=t.workspace_id WHERE {$daily} AND t.assignee_id IS NOT NULL AND t.archived_at IS NULL AND COALESCE(t.recurrence_status,'active')='active' AND (a.status IS NULL OR a.status NOT IN ('trash','archived')) AND (w.status IS NULL OR w.status NOT IN ('trash','archived')){$tpl_emp}");
                foreach($tpls as $tpl){
                    if($allowed!==null && !in_array((int)$tpl->workspace_id,$allowed,true)) continue;
                    if(empty($tpl->start_date) || $tpl->start_date>$today) continue;
                    if(!in_array($today,$this->occurrence_dates($tpl,$today,$today),true)) continue;
                    $has=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['history']} WHERE task_id=%d AND occurrence_date=%s LIMIT 1",$tpl->id,$today));
                    if($has) continue;
                    $k=(int)$tpl->assignee_id.'|'.$today;
                    if(!isset($out[$k])) $out[$k]=['employee_id'=>(int)$tpl->assignee_id,'date'=>$today,'due'=>0,'done'=>0,'not_done'=>[],'done_list'=>[]];
                    $out[$k]['due']++;
                    $ws_name=(string)$wpdb->get_var($wpdb->prepare("SELECT name FROM {$this->tables['workspaces']} WHERE id=%d",(int)$tpl->workspace_id));
                    $wa_name=(string)$wpdb->get_var($wpdb->prepare("SELECT name FROM {$this->tables['work_areas']} WHERE id=%d",(int)$tpl->work_area_id));
                    $out[$k]['not_done'][]=['task_id'=>(int)$tpl->id,'task_name'=>(string)($tpl->task_name?:'Recurring Task'),'workspace'=>$ws_name,'work_area'=>$wa_name,'due_time'=>(string)($tpl->due_time?:''),'status'=>'Pending','remarks'=>$remarks[(int)$tpl->id.'|'.$today]??[],'goal_target'=>(int)($tpl->goal_target??0),'goal_unit'=>(string)($tpl->goal_unit??''),'goal_done'=>(int)($goal_map[(int)$tpl->id.'|'.$today]??0)];
                    $tg=(int)($tpl->goal_target??0); $td=(int)($goal_map[(int)$tpl->id.'|'.$today]??0); if($tg>0 && $td>0) $out[$k]['done']+=min(1,$td/$tg);
                }
            }
        }
        // v4.18.164: all Daily recurring tasks assigned to each User (the master list), for the Employee Name pop-up.
        $assigned=[];
        if($employee_id!==-1){
            $daily_t="t.is_template=1 AND t.task_type='Recurring' AND COALESCE(NULLIF(t.frequency,''),'Daily')='Daily'";
            $te=$employee_id?$wpdb->prepare(' AND t.assignee_id=%d',$employee_id):'';
            $list=$wpdb->get_results("SELECT t.id,t.assignee_id,t.workspace_id,t.task_name,t.due_time,t.start_date,t.end_date,t.interval_value,COALESCE(t.recurrence_status,'active') recurrence_status,w.name workspace_name,a.name work_area_name FROM {$this->tables['tasks']} t LEFT JOIN {$this->tables['work_areas']} a ON a.id=t.work_area_id LEFT JOIN {$this->tables['workspaces']} w ON w.id=t.workspace_id WHERE {$daily_t} AND t.assignee_id IS NOT NULL AND t.archived_at IS NULL AND (a.status IS NULL OR a.status NOT IN ('trash','archived')) AND (w.status IS NULL OR w.status NOT IN ('trash','archived')){$te} ORDER BY w.name,a.name,t.task_name");
            foreach($list as $t){
                if($allowed!==null && !in_array((int)$t->workspace_id,$allowed,true)) continue;
                $assigned[(string)(int)$t->assignee_id][]=['task_id'=>(int)$t->id,'task_name'=>(string)($t->task_name?:'Recurring Task'),'workspace'=>(string)($t->workspace_name?:''),'work_area'=>(string)($t->work_area_name?:''),'due_time'=>(string)($t->due_time?:''),'start_date'=>(string)($t->start_date?:''),'end_date'=>(string)($t->end_date?:''),'interval'=>max(1,(int)$t->interval_value),'status'=>(string)$t->recurrence_status];
            }
        }
        wp_send_json_success(['month'=>$month,'from'=>$from,'to'=>$to,'rows'=>array_values($out),'assigned'=>$assigned]);
    }

    public function ajax_get_report(){
        $this->verify(); $f=$this->report_filters_from_request(); $data=$this->report_dataset($f,1000); $data['archive']=$this->archived_report_summary($f); $data['filters']=$f; wp_send_json_success($data);
    }

    public function export_report(){
        if(!is_user_logged_in()) wp_die('Login required','Workboard',['response'=>403]); check_admin_referer('bb_export_nonce','nonce');
        $f=$this->report_filters_from_request(); $data=$this->report_dataset($f,50000);
        nocache_headers(); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="business-board-report-'.$f['from'].'-to-'.$f['to'].'.csv"');
        $out=fopen('php://output','w'); fputcsv($out,['Type','Task','Workspace','Work Area','User','Due Date','Due Time','Status','Result','Completed At','Score Points']);
        foreach($data['rows'] as $r) fputcsv($out,[$r['type'],$r['task_name'],$r['workspace'],$r['work_area'],$r['employee'],$r['due_date'],$r['due_time'],$r['status'],$r['result'],$r['completed_at'],$r['score_points']]); fclose($out); exit;
    }

    private function portable_workspace_package($workspace_id){
        global $wpdb;
        $snap=$this->workspace_snapshot($workspace_id);
        if(!$snap) return null;
        $today=current_time('Y-m-d');
        $placeholders=[];
        foreach(($snap['tasks']??[]) as &$task){
            $employee_id=absint($task['assignee_id']??0);
            unset($task['assignee_id']);
            if($employee_id){
                $e=$wpdb->get_row($wpdb->prepare("SELECT e.*,a.name work_area_name FROM {$this->tables['employees']} e LEFT JOIN {$this->tables['work_areas']} a ON a.id=e.work_area_id WHERE e.id=%d",$employee_id));
                if($e){
                    $role=trim((string)$e->designation);
                    $basis=$role ?: trim((string)$e->name);
                    $area=trim((string)($e->work_area_name??''));
                    $key=sanitize_title(($area?$area.'-':'').$basis);
                    if(!$key) $key='assignee-'.$employee_id;
                    $label=$role ? (($area?$area.' · ':'').$role) : $e->name;
                    $ph=['key'=>$key,'label'=>$label,'name'=>$e->name,'designation'=>$role,'work_area'=>$area];
                    $task['assignee_placeholder']=$ph;
                    $placeholders[$key]=$ph;
                }
            }
            if(!empty($task['end_date'])){
                $task['end_offset_days']=(int)round((strtotime($task['end_date'])-strtotime($today))/DAY_IN_SECONDS);
            } else $task['end_offset_days']=null;
            unset($task['end_date']);
        }
        unset($task);
        return [
            'product'=>'Workboard','package_type'=>'workspace_structure','schema_version'=>1,'plugin_version'=>self::VERSION,
            'exported_at'=>current_time('mysql'),'portable'=>true,
            'workspace'=>$snap['workspace']??[], 'areas'=>$snap['areas']??[], 'tasks'=>$snap['tasks']??[],
            'assignee_placeholders'=>array_values($placeholders),
            'note'=>'Structure-only package. Completion history, scores, activity logs, notifications, proof files and source employee IDs are intentionally excluded.'
        ];
    }

    public function export_workspace_package(){
        if(!is_user_logged_in()) wp_die('Login required','Workboard',['response'=>403]);
        check_admin_referer('bb_export_nonce','nonce');
        if(!current_user_can('bb_export_data') && !current_user_can('manage_options')) wp_die('Export permission required','Workboard',['response'=>403]);
        $workspace_id=absint($_GET['workspace_id']??0);
        if(!$workspace_id || !$this->workspace_permission($workspace_id,'view')) wp_die('Workspace not found or access denied','Workboard',['response'=>403]);
        $package=$this->portable_workspace_package($workspace_id);
        if(!$package) wp_die('Workspace not found','Workboard',['response'=>404]);
        $slug=sanitize_file_name(($package['workspace']['name']??'workspace').'-workspace-package.json');
        nocache_headers(); header('Content-Type: application/json; charset=utf-8'); header('Content-Disposition: attachment; filename="'.$slug.'"');
        echo wp_json_encode($package,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES); exit;
    }

    public function ajax_import_workspace_package(){
        $this->verify(); $this->require_manage();
        global $wpdb;
        $raw=wp_unslash($_POST['package']??'');
        $pkg=json_decode($raw,true);
        if(!is_array($pkg) || !in_array(($pkg['product']??''),['Workboard','Business Board'],true) || ($pkg['package_type']??'')!=='workspace_structure') wp_send_json_error(['message'=>'Invalid Workboard Workspace package']);
        if((int)($pkg['schema_version']??0)!==1) wp_send_json_error(['message'=>'Unsupported Workspace package version']);
        $name=sanitize_text_field($_POST['workspace_name']??($pkg['workspace']['name']??''));
        if(!$name) wp_send_json_error(['message'=>'Workspace name is required']);
        if($wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['workspaces']} WHERE name=%s LIMIT 1",$name))) wp_send_json_error(['message'=>'A Workspace with this name already exists. Please use a different name.']);
        $assignment_items=$this->assignment_items_from_post('assignments');
        if(!$this->assignment_items_have_valid_workspace_owner($assignment_items)) wp_send_json_error(['message'=>'Please select at least one Workspace Owner.'],422);
        $folder_ids=$this->posted_folder_ids();
        $map=json_decode(wp_unslash($_POST['assignee_map']??'{}'),true); if(!is_array($map)) $map=[];
        $wpdb->query('START TRANSACTION');
        $workspace_data=[
            'name'=>$name,
            'slug'=>$this->unique_workspace_slug($name,0),
            'description'=>sanitize_text_field($pkg['workspace']['description']??''),
            'color'=>sanitize_hex_color($pkg['workspace']['color']??'#4f46e5')?:'#4f46e5',
            'status'=>'active',
            'sort_order'=>$this->next_workspace_sort_order(),
            'folder_id'=>($folder_ids[0]??null),
            'created_at'=>current_time('mysql')
        ];
        $ok=$wpdb->insert($this->tables['workspaces'],$workspace_data);
        $wid=(int)$wpdb->insert_id;
        if($ok===false || !$wid){
            $db_error=$wpdb->last_error ?: 'Unknown database error';
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message'=>'Could not create imported Workspace: '.$db_error],500);
        }
        if(!$this->set_workspace_folders($wid,$folder_ids)){ $wpdb->query('ROLLBACK'); wp_send_json_error(['message'=>'Could not save Folder selection for imported Workspace.'],500); }
        $this->save_entity_assignments_internal('workspace',$wid,$assignment_items);
        $area_by_name=[]; $area_count=0;
        foreach(($pkg['areas']??[]) as $i=>$a){
            $aid=$this->ensure_area_from_template($a,$wid); if(!$aid) continue;
            $area_by_name[(string)($a['name']??'')]=$aid;
            $wpdb->replace($this->tables['workspace_areas'],['workspace_id'=>$wid,'work_area_id'=>$aid,'sort_order'=>absint($a['sort_order']??$i)]); $area_count++;
        }
        $today=current_time('Y-m-d'); $task_count=0; $mapped_count=0;
        foreach(($pkg['tasks']??[]) as $td){
            $task_name=sanitize_text_field($td['task_name']??''); if(!$task_name) continue;
            $aid=(int)($area_by_name[(string)($td['work_area_name']??'')]??0); if(!$aid) continue;
            $assignee=0; $phkey=sanitize_key($td['assignee_placeholder']['key']??'');
            if($phkey && !empty($map[$phkey])){
                $candidate=absint($map[$phkey]);
                if($candidate && $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['employees']} WHERE id=%d",$candidate))){$assignee=$candidate;$mapped_count++;}
            }
            $is_recurring=(($td['task_type']??'One Time')==='Recurring');
            $due_date=null; $start_date=null; $end_date=null;
            if(!$is_recurring && $td['due_offset_days']!==null && $td['due_offset_days']!=='') $due_date=(new DateTime($today))->modify(((int)$td['due_offset_days']).' days')->format('Y-m-d');
            if($is_recurring){
                $offset=($td['start_offset_days']!==null && $td['start_offset_days']!=='')?(int)$td['start_offset_days']:0;
                $start_date=(new DateTime($today))->modify($offset.' days')->format('Y-m-d');
                if(isset($td['end_offset_days']) && $td['end_offset_days']!==null && $td['end_offset_days']!=='') $end_date=(new DateTime($today))->modify(((int)$td['end_offset_days']).' days')->format('Y-m-d');
            }
            $key=str_replace('-','',wp_generate_uuid4());
            $wpdb->insert($this->tables['tasks'],[
                'task_name'=>$task_name,'workspace_id'=>$wid,'work_area_id'=>$aid,'assignee_id'=>$assignee?:null,
                'priority'=>$this->normalize_priority($td['priority']??'Medium'),'task_type'=>$is_recurring?'Recurring':'One Time','template_key'=>$key,
                'is_template'=>$is_recurring?1:0,'frequency'=>$is_recurring?sanitize_text_field($td['frequency']??'Daily'):'None',
                'interval_value'=>max(1,absint($td['interval_value']??1)),'interval_unit'=>sanitize_text_field($td['interval_unit']??'Days'),'overdue_policy'=>$is_recurring?(sanitize_key($td['overdue_policy']??'until_next')==='keep'?'keep':'until_next'):'until_next',
                'start_date'=>$start_date,'end_date'=>$end_date,'due_date'=>$due_date,'due_time'=>sanitize_text_field($td['due_time']??'')?:null,'occurrence_date'=>$due_date,
                'description'=>sanitize_textarea_field($td['description']??''),'labels'=>sanitize_text_field($td['labels']??''),'estimated_minutes'=>absint($td['estimated_minutes']??0),
                'status'=>$is_recurring?'Template':'Pending','recurrence_status'=>'active','updated_at'=>current_time('mysql')
            ]);
            $tid=(int)$wpdb->insert_id; if(!$tid) continue; $task_count++;
            foreach(array_values($td['checklist']??[]) as $i=>$text){$text=sanitize_text_field($text);if($text)$wpdb->insert($this->tables['checklists'],['task_id'=>$tid,'item_text'=>$text,'sort_order'=>$i]);}
        }
        $this->log_activity('workspace_imported','Workspace imported from portable package',$wid);
        $wpdb->query('COMMIT');
        wp_send_json_success(['workspace_id'=>$wid,'workspace_name'=>$name,'areas'=>$area_count,'tasks'=>$task_count,'mapped_assignments'=>$mapped_count]);
    }

    private function backup_option_keys(){
        return ['bb_org_name','bb_org_logo_id','bb_org_mobile','bb_org_email','bb_org_address','bb_org_city','bb_org_state','bb_org_pin','bb_org_gstin','bb_org_pan','bb_org_website','bb_history_retention_months','bb_purge_proof_with_history','bb_upcoming_days','bb_notify_assignment','bb_notify_due_soon','bb_notify_overdue','bb_notify_daily_summary','bb_notify_morning_pending','bb_notify_email','bb_attendance_default_in_time','bb_attendance_default_out_time','bb_attendance_grace_minutes','bb_attendance_standard_break_minutes','bb_attendance_auto_break_after_minutes','bb_attendance_missing_out_cutoff','bb_attendance_missing_out_cutoff_mode','bb_attendance_default_weekly_off','bb_attendance_salary_basis','bb_show_module_attendance','bb_show_module_notes','bb_show_module_math','bb_show_module_billing','bb_show_module_leave','bb_show_module_timesheet','bb_show_module_callspace','bb_show_module_docspace','bb_show_workspace_delete_icons'];
    }

    private function build_backup_payload(){
        global $wpdb;
        $payload=['product'=>'Workboard','version'=>self::VERSION,'exported_at'=>current_time('mysql'),'site_url'=>home_url('/'),'blog_id'=>get_current_blog_id(),'note'=>'Media attachment files are not embedded; attachment records and WordPress media IDs are included. Network wallet and network messaging credentials are intentionally excluded.','options'=>[],'tables'=>[]];
        foreach($this->backup_option_keys() as $k) $payload['options'][$k]=get_option($k);
        foreach($this->tables as $key=>$table){
            $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)));
            $payload['tables'][$key]=$exists===$table?$wpdb->get_results("SELECT * FROM {$table}",ARRAY_A):[];
        }
        return $payload;
    }

    public function export_backup(){
        if(!is_user_logged_in()) wp_die('Login required','Workboard',['response'=>403]); check_admin_referer('bb_export_nonce','nonce');
        if(!current_user_can('bb_export_data') && !current_user_can('manage_options')) wp_die('Export permission required','Workboard',['response'=>403]);
        $payload=$this->build_backup_payload();
        nocache_headers(); header('Content-Type: application/json; charset=utf-8'); header('Content-Disposition: attachment; filename="business-board-backup-'.current_time('Y-m-d-His').'.json"'); echo wp_json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES); exit;
    }

    private function restore_wp_user_map($payload){
        $map=[]; $unmapped=0;
        $employees=is_array($payload['tables']['employees']??null)?$payload['tables']['employees']:[];
        foreach($employees as $row){
            $old=absint($row['wp_user_id']??0); if(!$old) continue;
            $email=sanitize_email($row['email']??''); $uid=0;
            if($email){ $u=get_user_by('email',$email); if($u) $uid=(int)$u->ID; }
            if(!$uid){ $u=get_user_by('id',$old); if($u) $uid=(int)$u->ID; }
            if($uid){
                $map[$old]=$uid;
                if(is_multisite() && !is_user_member_of_blog($uid,get_current_blog_id())) add_user_to_blog(get_current_blog_id(),$uid,'bb_employee');
            }else $unmapped++;
        }
        return [$map,$unmapped];
    }

    private function restore_remap_wp_user_fields($row,$map){
        $fields=['wp_user_id','user_id','created_by','uploaded_by','created_by_wp_user_id','changed_by_wp_user_id','reviewed_by_wp_user_id'];
        foreach($fields as $field){
            if(!array_key_exists($field,$row) || $row[$field]===null || $row[$field]==='') continue;
            $old=absint($row[$field]);
            if(isset($map[$old])) $row[$field]=$map[$old];
            elseif($old && !get_user_by('id',$old)) $row[$field]=null;
        }
        return $row;
    }

    public function restore_backup(){
        if(!is_user_logged_in()) wp_die('Login required','Workboard',['response'=>403]);
        check_admin_referer('bb_restore_nonce','nonce');
        if(!current_user_can('manage_options')) wp_die('Administrator permission required to restore a Workboard backup.','Workboard',['response'=>403]);
        if(empty($_POST['confirm_replace'])) wp_die('Please confirm that existing Workboard data may be replaced.','Workboard',['response'=>400]);
        if(empty($_FILES['backup_file']) || !isset($_FILES['backup_file']['error']) || (int)$_FILES['backup_file']['error']!==UPLOAD_ERR_OK) wp_die('Backup upload failed. Please select a valid Workboard JSON backup.','Workboard',['response'=>400]);
        $file=$_FILES['backup_file']; $size=absint($file['size']??0); $limit=min((int)wp_max_upload_size(),100*MB_IN_BYTES);
        if(!$size || $size>$limit) wp_die('Backup file is empty or exceeds the server upload limit.','Workboard',['response'=>400]);
        $name=sanitize_file_name($file['name']??''); if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='json') wp_die('Please upload a .json Workboard backup file.','Workboard',['response'=>400]);
        $raw=file_get_contents($file['tmp_name']); if($raw===false || $raw==='') wp_die('Could not read the uploaded backup.','Workboard',['response'=>400]);
        $payload=json_decode($raw,true); if(!is_array($payload) || ($payload['product']??'')!=='Workboard' || !is_array($payload['tables']??null)) wp_die('This file is not a valid Workboard backup.','Workboard',['response'=>400]);
        $backup_version=sanitize_text_field($payload['version']??'0'); if($backup_version && version_compare($backup_version,self::VERSION,'>')) wp_die('This backup was created by Workboard '.$backup_version.'. Please update this site to that version or newer before restoring.','Workboard',['response'=>400]);
        global $wpdb; [$user_map,$unmapped]=$this->restore_wp_user_map($payload); $inserted=0;
        $wpdb->query('START TRANSACTION');
        try{
            foreach($this->tables as $key=>$table){
                $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table))); if($exists!==$table) continue;
                $ok=$wpdb->query("DELETE FROM {$table}"); if($ok===false) throw new Exception('Could not clear '.$key.'.');
            }
            foreach($this->tables as $key=>$table){
                $rows=$payload['tables'][$key]??[]; if(!is_array($rows) || !$rows) continue;
                $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table))); if($exists!==$table) continue;
                $column_rows=$wpdb->get_results("SHOW COLUMNS FROM {$table}",ARRAY_A); $columns=[]; foreach($column_rows as $c) if(!empty($c['Field'])) $columns[$c['Field']]=true;
                foreach($rows as $row){
                    if(!is_array($row)) continue; $clean=[];
                    foreach($row as $col=>$val) if(isset($columns[$col])) $clean[$col]=$val;
                    if(!$clean) continue; $clean=$this->restore_remap_wp_user_fields($clean,$user_map);
                    if($wpdb->insert($table,$clean)===false) throw new Exception('Could not restore '.$key.' record: '.$wpdb->last_error);
                    $inserted++;
                }
            }
            if(is_array($payload['options']??null)) foreach($this->backup_option_keys() as $k) if(array_key_exists($k,$payload['options'])) update_option($k,$payload['options'][$k]);
            $wpdb->query('COMMIT'); wp_cache_flush();
        }catch(Throwable $e){ $wpdb->query('ROLLBACK'); wp_die('Restore failed: '.esc_html($e->getMessage()),'Workboard',['response'=>500]); }
        $url=add_query_arg(['page'=>'workboard-settings','bb_restore'=>'success','rows'=>$inserted,'unmapped'=>$unmapped],admin_url('options-general.php'));
        wp_safe_redirect($url); exit;
    }

    private function ensure_party_share_schema(){
        global $wpdb;
        $table=$this->tables['parties'];
        foreach(['login_enabled'=>"ALTER TABLE {$table} ADD COLUMN login_enabled tinyint(1) DEFAULT 0 AFTER pin",'login_id'=>"ALTER TABLE {$table} ADD COLUMN login_id varchar(120) DEFAULT '' AFTER login_enabled",'wp_user_id'=>"ALTER TABLE {$table} ADD COLUMN wp_user_id bigint(20) unsigned DEFAULT NULL AFTER login_id"] as $col=>$sql){
            if(!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s",$col))) $wpdb->query($sql);
        }
        require_once ABSPATH.'wp-admin/includes/upgrade.php'; $charset=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$this->tables['work_area_parties']} (work_area_id bigint(20) unsigned NOT NULL,party_id bigint(20) unsigned NOT NULL,rights varchar(100) DEFAULT 'view',created_at datetime DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (work_area_id,party_id),KEY party_id (party_id)) $charset;");
        dbDelta("CREATE TABLE {$this->tables['workspace_parties']} (workspace_id bigint(20) unsigned NOT NULL,party_id bigint(20) unsigned NOT NULL,rights varchar(100) DEFAULT 'view',created_at datetime DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (workspace_id,party_id),KEY party_id (party_id)) $charset;");
        $has_party_rights=$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$this->tables['work_area_parties']} LIKE %s",'rights')); if(!$has_party_rights) $wpdb->query("ALTER TABLE {$this->tables['work_area_parties']} ADD COLUMN rights varchar(100) DEFAULT 'view' AFTER party_id");
        if(!get_role('bb_party')) add_role('bb_party','Workboard Party',['read'=>true]);
        if(get_option('bb_enable_party_share')===false) update_option('bb_enable_party_share',0);
    }

    private function ensure_timesheet_table(){
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $charset=$wpdb->get_charset_collate();
        $table=$this->tables['timesheet'];
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned DEFAULT NULL,
            employee_id bigint(20) unsigned DEFAULT NULL,
            workspace_name varchar(190) DEFAULT '',
            work_area_name varchar(190) DEFAULT '',
            activity text NOT NULL,
            remarks text DEFAULT NULL,
            duration_minutes int unsigned NOT NULL DEFAULT 0,
            entry_mode varchar(12) NOT NULL DEFAULT 'range',
            activity_at datetime NOT NULL,
            ended_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY employee_id (employee_id),
            KEY activity_at (activity_at)
        ) $charset;");
        foreach([
            'workspace_name'=>"ALTER TABLE {$table} ADD COLUMN workspace_name varchar(190) DEFAULT '' AFTER employee_id",
            'work_area_name'=>"ALTER TABLE {$table} ADD COLUMN work_area_name varchar(190) DEFAULT '' AFTER workspace_name",
            'remarks'=>"ALTER TABLE {$table} ADD COLUMN remarks text DEFAULT NULL AFTER activity",
            'entry_mode'=>"ALTER TABLE {$table} ADD COLUMN entry_mode varchar(12) NOT NULL DEFAULT 'range' AFTER duration_minutes"
        ] as $col=>$sql){
            if(!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s",$col))) $wpdb->query($sql);
        }
        if(get_option('bb_show_module_timesheet')===false) update_option('bb_show_module_timesheet',0);
        if(get_option('bb_show_module_callspace')===false) update_option('bb_show_module_callspace',0);
        if(get_option('bb_show_module_docspace')===false) update_option('bb_show_module_docspace',0);
    }

    private function parse_timesheet_minutes($raw){
        $raw=trim((string)$raw);
        if($raw==='') return 0;
        if(preg_match('/^(\d{1,3})\s*:\s*([0-5]?\d)$/',$raw,$m)) return ((int)$m[1]*60)+(int)$m[2];
        if(preg_match('/^(\d+(?:\.\d+)?)\s*(h|hr|hrs|hour|hours)$/i',$raw,$m)) return (int)round((float)$m[1]*60);
        if(preg_match('/^(\d+)\s*(m|min|mins|minute|minutes)$/i',$raw,$m)) return (int)$m[1];
        if(is_numeric($raw)) return (int)round((float)$raw*60);
        return -1;
    }

    private function timesheet_row_state($r){
        $minutes=max(0,(int)($r->duration_minutes??0));
        $hours=(int)floor($minutes/60); $mins=$minutes%60;
        $time_label=$hours>0 ? $hours.' Hr'.($hours===1?'':'s').($mins?' '.$mins.' Min':'') : $mins.' Min';
        $activity_at=(string)($r->activity_at??'');
        $ended_at=(string)($r->ended_at??'');
        // Time Sheet stores its audit timestamps using the WordPress site timezone.
        // Convert only for the PDF display; keep the stored timestamp untouched.
        $india_updated_at='';
        $raw_updated_at=trim((string)($r->updated_at??''));
        if($raw_updated_at!==''){
            $parsed_updated_at=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$raw_updated_at,wp_timezone());
            if($parsed_updated_at instanceof DateTimeImmutable){
                $india_updated_at=$parsed_updated_at->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('d/m/Y H:i:s');
            }
        }
        return [
            'id'=>(int)$r->id,
            'userId'=>(int)($r->user_id??0),
            'employeeId'=>(int)($r->employee_id??0),
            'userName'=>(string)($r->employee_name??$r->user_name??'User'),
            'workspaceName'=>(string)($r->workspace_name??''),
            'workAreaName'=>(string)($r->work_area_name??''),
            'activity'=>(string)($r->activity??''),
            'remarks'=>(string)($r->remarks??''),
            'durationMinutes'=>$minutes,
            'entryMode'=>(string)($r->entry_mode??'range'),
            'timeLabel'=>$time_label,
            'activityAt'=>$activity_at,
            'endedAt'=>$ended_at,
            'activityDate'=>$activity_at?wp_date('Y-m-d',strtotime($activity_at)):'',
            'fromTime'=>$activity_at?wp_date('H:i',strtotime($activity_at)):'',
            'toTime'=>$ended_at?wp_date('H:i',strtotime($ended_at)):'',
            'createdAt'=>(string)($r->created_at??''),
            'updatedAt'=>(string)($r->updated_at??''),
            'updatedAtIndia'=>$india_updated_at,
        ];
    }


    private function ensure_callspace_docspace_tables(){
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $charset=$wpdb->get_charset_collate();
        $call=$this->tables['callspace'];
        $doc=$this->tables['docspace'];
        dbDelta("CREATE TABLE {$call} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            entry_date date NOT NULL,
            entry_time time DEFAULT NULL,
            minutes int unsigned DEFAULT NULL,
            type varchar(20) NOT NULL DEFAULT 'Call',
            party varchar(190) DEFAULT '',
            caller varchar(190) DEFAULT '',
            recipient varchar(190) DEFAULT '',
            report_to bigint(20) unsigned DEFAULT NULL,
            remarks text DEFAULT NULL,
            task_id bigint(20) unsigned DEFAULT NULL,
            created_by bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY entry_date (entry_date),
            KEY type (type),
            KEY created_by (created_by),
            KEY report_to (report_to),
            KEY task_id (task_id)
        ) {$charset};");
        $call_tasks=$this->tables['callspace_tasks'];
        dbDelta("CREATE TABLE {$call_tasks} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            callspace_id bigint(20) unsigned NOT NULL,
            task_id bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY callspace_task (callspace_id,task_id),
            KEY callspace_id (callspace_id),
            KEY task_id (task_id)
        ) {$charset};");
        dbDelta("CREATE TABLE {$doc} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            doc_no varchar(60) DEFAULT NULL,
            doc_date date NOT NULL,
            direction varchar(20) NOT NULL DEFAULT 'Inward',
            sender varchar(190) NOT NULL DEFAULT '',
            recipient varchar(190) NOT NULL DEFAULT '',
            mode varchar(20) NOT NULL DEFAULT 'Hand',
            courier_no varchar(190) DEFAULT '',
            courier_name varchar(190) DEFAULT '',
            document_details text DEFAULT NULL,
            remarks text DEFAULT NULL,
            attachment_id bigint(20) unsigned DEFAULT NULL,
            created_by bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY doc_no (doc_no),
            KEY doc_date (doc_date),
            KEY direction (direction),
            KEY mode (mode),
            KEY created_by (created_by)
        ) {$charset};");
        if(get_option('bb_show_module_callspace')===false) update_option('bb_show_module_callspace',0);
        if(get_option('bb_show_module_docspace')===false) update_option('bb_show_module_docspace',0);
    }

    private function communication_row_can_edit($created_by){
        // Employees can edit only the CallSpace/DocSpace rows they created themselves.
        // Owner/Manager/Admin retain edit access to all rows.
        return $this->is_privileged_user() || (int)$created_by===(int)get_current_user_id();
    }

    private function communication_row_can_delete($created_by){
        // Keep deletion restricted to privileged users; employees get Edit on their own rows only.
        return $this->is_privileged_user();
    }

    private function callspace_row_state($r){
        return [
            'id'=>(int)$r->id,'entryDate'=>(string)$r->entry_date,'entryTime'=>(string)($r->entry_time??''),
            'minutes'=>$r->minutes===null?null:(int)$r->minutes,'type'=>(string)$r->type,
            'party'=>(string)$r->party,'caller'=>(string)$r->caller,'recipient'=>(string)$r->recipient,
            'reportToId'=>(int)($r->report_to??0),'reportToName'=>(string)($r->report_to_name??''),
            'remarks'=>(string)$r->remarks,'taskId'=>$r->task_id?(int)$r->task_id:null,'taskCount'=>(int)($r->task_count??0),
            'createdBy'=>(int)$r->created_by,'createdByName'=>(string)($r->created_by_name??''),
            'canEdit'=>$this->communication_row_can_edit($r->created_by)?1:0,'canDelete'=>$this->communication_row_can_delete($r->created_by)?1:0,'createdAt'=>(string)$r->created_at,
        ];
    }

    private function docspace_row_state($r){
        return [
            'id'=>(int)$r->id,'docNo'=>(string)$r->doc_no,'docDate'=>(string)$r->doc_date,
            'direction'=>(string)$r->direction,'sender'=>(string)$r->sender,'recipient'=>(string)$r->recipient,
            'mode'=>(string)$r->mode,'courierNo'=>(string)$r->courier_no,'courierName'=>(string)$r->courier_name,
            'documentDetails'=>(string)$r->document_details,'remarks'=>(string)$r->remarks,
            'attachmentId'=>(int)($r->attachment_id??0),
            'attachmentUrl'=>!empty($r->attachment_id)?(string)(wp_get_attachment_url((int)$r->attachment_id)?:''):'',
            'attachmentName'=>!empty($r->attachment_id)?(string)get_the_title((int)$r->attachment_id):'',
            'createdBy'=>(int)$r->created_by,'createdByName'=>(string)($r->created_by_name??''),
            'canEdit'=>$this->communication_row_can_edit($r->created_by)?1:0,'canDelete'=>$this->communication_row_can_delete($r->created_by)?1:0,'createdAt'=>(string)$r->created_at,
        ];
    }

    public function ajax_get_callspace_entries(){
        $this->verify();
        if((int)get_option('bb_show_module_callspace',0)!==1) wp_send_json_error(['message'=>'CallSpace module is disabled'],403);
        global $wpdb;
        $rows=$wpdb->get_results("SELECT c.*,u.display_name created_by_name,e.name report_to_name,
            ((CASE WHEN c.task_id IS NOT NULL AND EXISTS(SELECT 1 FROM {$this->tables['tasks']} t0 WHERE t0.id=c.task_id) THEN 1 ELSE 0 END) +
             (SELECT COUNT(DISTINCT ct.task_id) FROM {$this->tables['callspace_tasks']} ct INNER JOIN {$this->tables['tasks']} t1 ON t1.id=ct.task_id WHERE ct.callspace_id=c.id AND (c.task_id IS NULL OR ct.task_id<>c.task_id))) AS task_count
            FROM {$this->tables['callspace']} c LEFT JOIN {$wpdb->users} u ON u.ID=c.created_by LEFT JOIN {$this->tables['employees']} e ON e.id=c.report_to ORDER BY c.entry_date DESC,c.entry_time DESC,c.id DESC LIMIT 3000");
        wp_send_json_success(['entries'=>array_map([$this,'callspace_row_state'],$rows)]);
    }

    public function ajax_save_callspace_entry(){
        $this->verify();
        if((int)get_option('bb_show_module_callspace',0)!==1) wp_send_json_error(['message'=>'CallSpace module is disabled'],403);
        global $wpdb;
        $id=absint($_POST['id']??0);
        if($id){
            $old=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['callspace']} WHERE id=%d",$id));
            if(!$old) wp_send_json_error(['message'=>'CallSpace entry not found'],404);
            if(!$this->communication_row_can_edit($old->created_by)) wp_send_json_error(['message'=>'You cannot edit this CallSpace entry'],403);
        }
        $entry_datetime=trim(sanitize_text_field(wp_unslash($_POST['entry_datetime']??'')));
        $date=''; $time='';
        if($entry_datetime!=='' && preg_match('/^(\d{4}-\d{2}-\d{2})[T ]([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/',$entry_datetime,$m)){
            $date=$m[1]; $time=$m[2].':'.$m[3].':00';
        } elseif($entry_datetime!=='' && preg_match('/^(\d{2})\/(\d{2})\/(\d{4})\s+([01]\d|2[0-3]):([0-5]\d)$/',$entry_datetime,$m)){
            $date=$m[3].'-'.$m[2].'-'.$m[1]; $time=$m[4].':'.$m[5].':00';
        } else {
            // Backward compatibility for any older frontend still posting separate date/time values.
            $date=sanitize_text_field(wp_unslash($_POST['entry_date']??''));
            $raw_time=sanitize_text_field(wp_unslash($_POST['entry_time']??''));
            if($raw_time!=='' && preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/',$raw_time,$tm)) $time=$tm[1].':'.$tm[2].':00';
        }
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) wp_send_json_error(['message'=>'Select a valid Date & Time'],422);
        if($time==='') $time=current_time('H:i:s');
        $type=sanitize_text_field(wp_unslash($_POST['type']??'Call'));
        if(!in_array($type,['Call','Message','Email'],true)) $type='Call';
        $party=trim(sanitize_text_field(wp_unslash($_POST['party']??'')));
        $caller=trim(sanitize_text_field(wp_unslash($_POST['caller']??'')));
        $recipient=trim(sanitize_text_field(wp_unslash($_POST['recipient']??'')));
        $report_to=absint($_POST['report_to']??0);
        if($report_to){
            $valid_report_to=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['employees']} WHERE id=%d AND status<>'inactive'",$report_to));
            if(!$valid_report_to) wp_send_json_error(['message'=>'Select a valid Report To user'],422);
        }
        $remarks=trim(sanitize_textarea_field(wp_unslash($_POST['remarks']??'')));
        if($party==='') wp_send_json_error(['message'=>'Party is required'],422);
        if($remarks==='') wp_send_json_error(['message'=>'Remarks is required'],422);
        $minutes=($_POST['minutes']??'')===''?null:max(0,absint($_POST['minutes']));
        $data=['entry_date'=>$date,'entry_time'=>$time,'minutes'=>$minutes,'type'=>$type,'party'=>$party,'caller'=>$caller,'recipient'=>$recipient,'report_to'=>$report_to?:null,'remarks'=>$remarks,'updated_at'=>current_time('mysql')];
        if($id){ $ok=$wpdb->update($this->tables['callspace'],$data,['id'=>$id]); }
        else { $data['created_by']=get_current_user_id();$data['created_at']=current_time('mysql');$ok=$wpdb->insert($this->tables['callspace'],$data);$id=(int)$wpdb->insert_id; }
        if($ok===false) wp_send_json_error(['message'=>'Could not save CallSpace entry'],500);
        $row=$wpdb->get_row($wpdb->prepare("SELECT c.*,u.display_name created_by_name,e.name report_to_name,
            ((CASE WHEN c.task_id IS NOT NULL AND EXISTS(SELECT 1 FROM {$this->tables['tasks']} t0 WHERE t0.id=c.task_id) THEN 1 ELSE 0 END) +
             (SELECT COUNT(DISTINCT ct.task_id) FROM {$this->tables['callspace_tasks']} ct INNER JOIN {$this->tables['tasks']} t1 ON t1.id=ct.task_id WHERE ct.callspace_id=c.id AND (c.task_id IS NULL OR ct.task_id<>c.task_id))) AS task_count
            FROM {$this->tables['callspace']} c LEFT JOIN {$wpdb->users} u ON u.ID=c.created_by LEFT JOIN {$this->tables['employees']} e ON e.id=c.report_to WHERE c.id=%d",$id));
        wp_send_json_success(['entry'=>$this->callspace_row_state($row)]);
    }

    public function ajax_delete_callspace_entry(){
        $this->verify(); global $wpdb; $id=absint($_POST['id']??0);
        $row=$id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['callspace']} WHERE id=%d",$id)):null;
        if(!$row) wp_send_json_error(['message'=>'CallSpace entry not found'],404);
        if(!$this->communication_row_can_delete($row->created_by)) wp_send_json_error(['message'=>'You cannot delete this CallSpace entry'],403);
        if($wpdb->delete($this->tables['callspace'],['id'=>$id],['%d'])===false) wp_send_json_error(['message'=>'Could not delete CallSpace entry'],500);
        wp_send_json_success(['id'=>$id]);
    }

    private function next_docspace_no(){
        global $wpdb; $year=(int)wp_date('Y'); $month=(int)wp_date('n'); $fy_start=$month>=4?$year:$year-1;
        $fy=substr((string)$fy_start,-2).'/'.substr((string)($fy_start+1),-2); $prefix='DOC-'.$fy.'-';
        $like=$wpdb->esc_like($prefix).'%'; $last=(string)$wpdb->get_var($wpdb->prepare("SELECT doc_no FROM {$this->tables['docspace']} WHERE doc_no LIKE %s ORDER BY id DESC LIMIT 1",$like));
        $seq=0; if($last && preg_match('/-(\d+)$/',$last,$m)) $seq=(int)$m[1];
        return $prefix.str_pad((string)($seq+1),5,'0',STR_PAD_LEFT);
    }

    public function ajax_get_docspace_entries(){
        $this->verify();
        if((int)get_option('bb_show_module_docspace',0)!==1) wp_send_json_error(['message'=>'DocSpace module is disabled'],403);
        global $wpdb;
        $rows=$wpdb->get_results("SELECT d.*,u.display_name created_by_name FROM {$this->tables['docspace']} d LEFT JOIN {$wpdb->users} u ON u.ID=d.created_by ORDER BY d.doc_date DESC,d.id DESC LIMIT 3000");
        wp_send_json_success(['entries'=>array_map([$this,'docspace_row_state'],$rows)]);
    }

    /**
     * Optimise a DocSpace attachment without changing the public attachment URL.
     * Images are resized to a sensible document-viewing resolution and recompressed.
     * PDFs are compressed only when Ghostscript is available and the PDF does not
     * appear to contain a digital signature. Office files are stored unchanged.
     */
    private function optimise_docspace_attachment($attachment_id,$mime=''){
        $attachment_id=absint($attachment_id);
        if(!$attachment_id) return;
        $path=get_attached_file($attachment_id);
        if(!$path || !is_file($path)) return;
        $mime=$mime ?: (string)get_post_mime_type($attachment_id);

        if(in_array($mime,['image/jpeg','image/png','image/webp'],true)){
            $editor=wp_get_image_editor($path);
            if(!is_wp_error($editor)){
                $size=$editor->get_size();
                $w=(int)($size['width']??0); $h=(int)($size['height']??0);
                $max=2000;
                if($w>$max || $h>$max) $editor->resize($max,$max,false);
                if(method_exists($editor,'set_quality')) $editor->set_quality(80);
                $saved=$editor->save($path);
                if(!is_wp_error($saved)){
                    $meta=wp_generate_attachment_metadata($attachment_id,$path);
                    if(!is_wp_error($meta) && is_array($meta)) wp_update_attachment_metadata($attachment_id,$meta);
                }
            }
            return;
        }

        if($mime==='application/pdf') $this->optimise_docspace_pdf($path);
    }

    /** Best-effort PDF optimisation. Signed PDFs are deliberately left untouched. */
    private function optimise_docspace_pdf($path){
        if(!$path || !is_file($path) || filesize($path)<350*1024) return;
        $head=@file_get_contents($path,false,null,0,min((int)filesize($path),2*1024*1024));
        if(is_string($head) && (strpos($head,'/ByteRange')!==false || strpos($head,'/Type /Sig')!==false || strpos($head,'/Sig')!==false)) return;
        if(!function_exists('shell_exec')) return;
        $disabled=array_map('trim',explode(',',(string)ini_get('disable_functions')));
        if(in_array('shell_exec',$disabled,true)) return;
        $gs=trim((string)@shell_exec('command -v gs 2>/dev/null'));
        if($gs==='') return;
        $tmp=$path.'.bb-opt.pdf';
        $cmd=escapeshellarg($gs).' -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS=/ebook -dNOPAUSE -dQUIET -dBATCH -dDetectDuplicateImages=true -dCompressFonts=true -sOutputFile='.escapeshellarg($tmp).' '.escapeshellarg($path).' 2>&1';
        @shell_exec($cmd);
        if(is_file($tmp)){
            $old=(int)filesize($path); $new=(int)filesize($tmp);
            if($new>0 && $old>0 && $new < ($old*0.95)) @rename($tmp,$path); else @unlink($tmp);
        }
    }

    public function ajax_save_docspace_entry(){
        $this->verify();
        if((int)get_option('bb_show_module_docspace',0)!==1) wp_send_json_error(['message'=>'DocSpace module is disabled'],403);
        global $wpdb; $id=absint($_POST['id']??0);
        if($id){
            $old=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['docspace']} WHERE id=%d",$id));
            if(!$old) wp_send_json_error(['message'=>'DocSpace entry not found'],404);
            if(!$this->communication_row_can_edit($old->created_by)) wp_send_json_error(['message'=>'You cannot edit this DocSpace entry'],403);
        }
        $date=sanitize_text_field(wp_unslash($_POST['doc_date']??''));
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) wp_send_json_error(['message'=>'Select a valid Date'],422);
        $direction=sanitize_text_field(wp_unslash($_POST['direction']??'Inward')); if(!in_array($direction,['Inward','Outward'],true)) $direction='Inward';
        $sender=trim(sanitize_text_field(wp_unslash($_POST['sender']??''))); $recipient=trim(sanitize_text_field(wp_unslash($_POST['recipient']??'')));
        $mode=sanitize_text_field(wp_unslash($_POST['mode']??'Hand')); if(!in_array($mode,['Hand','Courier'],true)) $mode='Hand';
        if($sender===''||$recipient==='') wp_send_json_error(['message'=>'Sender and Recipient are required'],422);
        $courier_no=$mode==='Courier'?trim(sanitize_text_field(wp_unslash($_POST['courier_no']??''))):'';
        $courier_name=$mode==='Courier'?trim(sanitize_text_field(wp_unslash($_POST['courier_name']??''))):'';
        $details=trim(sanitize_textarea_field(wp_unslash($_POST['document_details']??''))); $remarks=trim(sanitize_textarea_field(wp_unslash($_POST['remarks']??'')));
        $attachment_id=$id?(int)($old->attachment_id??0):0;
        if(!empty($_FILES['document_file']['name'])){
            // v4.18.45: allow a larger source file, then optimise safe document types after upload.
            // The web server / PHP upload_max_filesize may still impose a lower server-level limit.
            if(!empty($_FILES['document_file']['size']) && (int)$_FILES['document_file']['size']>20*1024*1024) wp_send_json_error(['message'=>'Document must be 20 MB or smaller before optimisation.'],422);
            $check=wp_check_filetype_and_ext($_FILES['document_file']['tmp_name'],$_FILES['document_file']['name']);
            $mime=(string)($check['type']??'');
            $allowed=['application/pdf','image/jpeg','image/png','image/webp','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
            if(!$mime || !in_array($mime,$allowed,true)) wp_send_json_error(['message'=>'Upload PDF, JPG, PNG, WEBP, DOC, DOCX, XLS or XLSX only.'],422);
            require_once ABSPATH.'wp-admin/includes/file.php';
            require_once ABSPATH.'wp-admin/includes/media.php';
            require_once ABSPATH.'wp-admin/includes/image.php';
            $new_attachment=media_handle_upload('document_file',0);
            if(is_wp_error($new_attachment)) wp_send_json_error(['message'=>$new_attachment->get_error_message()],422);
            $this->optimise_docspace_attachment((int)$new_attachment,$mime);
            if($attachment_id && $attachment_id!==(int)$new_attachment) wp_delete_attachment($attachment_id,true);
            $attachment_id=(int)$new_attachment;
        }
        $data=['doc_date'=>$date,'direction'=>$direction,'sender'=>$sender,'recipient'=>$recipient,'mode'=>$mode,'courier_no'=>$courier_no,'courier_name'=>$courier_name,'document_details'=>$details,'remarks'=>$remarks,'attachment_id'=>$attachment_id,'updated_at'=>current_time('mysql')];
        if($id){ $ok=$wpdb->update($this->tables['docspace'],$data,['id'=>$id]); }
        else { $data['doc_no']=$this->next_docspace_no();$data['created_by']=get_current_user_id();$data['created_at']=current_time('mysql');$ok=$wpdb->insert($this->tables['docspace'],$data);$id=(int)$wpdb->insert_id; }
        if($ok===false) wp_send_json_error(['message'=>'Could not save DocSpace entry'],500);
        $row=$wpdb->get_row($wpdb->prepare("SELECT d.*,u.display_name created_by_name FROM {$this->tables['docspace']} d LEFT JOIN {$wpdb->users} u ON u.ID=d.created_by WHERE d.id=%d",$id));
        wp_send_json_success(['entry'=>$this->docspace_row_state($row)]);
    }

    public function ajax_delete_docspace_entry(){
        $this->verify(); global $wpdb; $id=absint($_POST['id']??0);
        $row=$id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['docspace']} WHERE id=%d",$id)):null;
        if(!$row) wp_send_json_error(['message'=>'DocSpace entry not found'],404);
        if(!$this->communication_row_can_delete($row->created_by)) wp_send_json_error(['message'=>'You cannot delete this DocSpace entry'],403);
        $attachment_id=(int)($row->attachment_id??0);
        if($wpdb->delete($this->tables['docspace'],['id'=>$id],['%d'])===false) wp_send_json_error(['message'=>'Could not delete DocSpace entry'],500);
        if($attachment_id) wp_delete_attachment($attachment_id,true);
        wp_send_json_success(['id'=>$id]);
    }

    public function ajax_get_timesheet_task_options(){
        $this->verify();
        if((int)get_option('bb_show_module_timesheet',0)!==1) wp_send_json_error(['message'=>'Time Sheet module is disabled'],403);
        global $wpdb;
        $allowed=$this->allowed_workspace_ids();
        $where=[
            "t.archived_at IS NULL",
            "t.status NOT IN ('Cancelled','Archived')",
            "w.status NOT IN ('trash','archived')",
            "a.status NOT IN ('trash','archived')"
        ];
        if($allowed!==null){
            if(!$allowed) wp_send_json_success(['options'=>[]]);
            $where[]='t.workspace_id IN ('.implode(',',array_map('intval',$allowed)).')';
        }
        $sql="SELECT t.id,t.task_name,t.workspace_id,t.work_area_id,w.name workspace_name,a.name work_area_name
              FROM {$this->tables['tasks']} t
              INNER JOIN {$this->tables['workspaces']} w ON w.id=t.workspace_id
              INNER JOIN {$this->tables['work_areas']} a ON a.id=t.work_area_id
              WHERE ".implode(' AND ',$where)."
              ORDER BY w.name,a.name,t.task_name,t.id";
        $rows=$wpdb->get_results($sql);
        $out=[]; $seen=[];
        foreach($rows as $r){
            if($allowed!==null && !$this->task_permission((int)$r->id,'view')) continue;
            $task=trim((string)$r->task_name);
            if($task==='') continue;
            $key=(int)$r->workspace_id.'|'.(int)$r->work_area_id.'|'.strtolower($task);
            if(isset($seen[$key])) continue;
            $seen[$key]=1;
            $out[]=[
                'workspace_id'=>(int)$r->workspace_id,
                'workspace_name'=>(string)$r->workspace_name,
                'work_area_id'=>(int)$r->work_area_id,
                'work_area_name'=>(string)$r->work_area_name,
                'task_name'=>$task,
            ];
        }
        wp_send_json_success(['options'=>$out]);
    }

    public function ajax_get_timesheet_entries(){
        $this->verify();
        if((int)get_option('bb_show_module_timesheet',0)!==1) wp_send_json_error(['message'=>'Time Sheet module is disabled'],403);
        global $wpdb;
        $table=$this->tables['timesheet']; $employees=$this->tables['employees'];
        $priv=$this->is_privileged_user(); $uid=get_current_user_id(); $ce=$this->current_employee();
        $where=['1=1']; $args=[];
        if(!$priv){
            if($ce){ $where[]='(t.employee_id=%d OR (t.employee_id IS NULL AND t.user_id=%d))'; $args[]=(int)$ce->id; $args[]=$uid; }
            else { $where[]='t.user_id=%d'; $args[]=$uid; }
        }
        $sql="SELECT t.*,e.name employee_name,u.display_name user_name FROM {$table} t LEFT JOIN {$employees} e ON e.id=t.employee_id LEFT JOIN {$wpdb->users} u ON u.ID=t.user_id WHERE ".implode(' AND ',$where)." ORDER BY t.activity_at DESC,t.id DESC LIMIT 2000";
        if($args) $sql=$wpdb->prepare($sql,$args);
        $rows=$wpdb->get_results($sql);
        wp_send_json_success(['entries'=>array_map([$this,'timesheet_row_state'],$rows),'canViewAll'=>$priv?1:0,'currentEmployeeId'=>$ce?(int)$ce->id:0]);
    }

    public function ajax_save_timesheet_entry(){
        $this->verify();
        if((int)get_option('bb_show_module_timesheet',0)!==1) wp_send_json_error(['message'=>'Time Sheet module is disabled'],403);
        global $wpdb;

        $priv=$this->is_privileged_user(); $uid=get_current_user_id(); $ce=$this->current_employee();
        $default_employee_id=$ce?(int)$ce->id:0;

        $rows=[];
        $rows_json=wp_unslash($_POST['rows_json']??'');
        if($rows_json!==''){
            $decoded=json_decode($rows_json,true);
            if(is_array($decoded)) $rows=$decoded;
        }
        if(!$rows){
            $rows=[[ 
                'activity_from'=>$_POST['activity_from']??'',
                'activity_to'=>$_POST['activity_to']??'',
                'activity'=>$_POST['activity']??'',
                'remarks'=>$_POST['remarks']??'',
                'workspace_name'=>$_POST['workspace_name']??'',
                'work_area_name'=>$_POST['work_area_name']??'',
                'employee_id'=>$_POST['employee_id']??$default_employee_id,
                'activity_date'=>$_POST['activity_date']??'',
                'from_time'=>$_POST['from_time']??'',
                'to_time'=>$_POST['to_time']??'',
            ]];
        }

        $parse_dt=function($value){
            $raw=trim(sanitize_text_field(wp_unslash((string)$value)));
            if($raw==='') return null;
            $raw=str_replace('T',' ',$raw);
            $dt=date_create_from_format('Y-m-d H:i',$raw,wp_timezone());
            if(!$dt) $dt=date_create_from_format('Y-m-d H:i:s',$raw,wp_timezone());
            return $dt?:null;
        };

        $saved=[];
        foreach($rows as $i=>$row){
            if(!is_array($row)) continue;
            $activity=trim(sanitize_textarea_field(wp_unslash($row['activity']??'')));
            if(function_exists('mb_strlen') && mb_strlen($activity)>2000) $activity=mb_substr($activity,0,2000);
            $remarks=trim(sanitize_textarea_field(wp_unslash($row['remarks']??'')));
            if($remarks==='') wp_send_json_error(['message'=>'Remarks is required in row '.($i+1)],422);
            if(function_exists('mb_strlen') && mb_strlen($remarks)>2000) $remarks=mb_substr($remarks,0,2000);
            $workspace_name=sanitize_text_field(wp_unslash($row['workspace_name']??''));
            $work_area_name=sanitize_text_field(wp_unslash($row['work_area_name']??''));

            $from_dt=null; $to_dt=null;
            if(!empty($row['activity_from']) || !empty($row['activity_to'])){
                $from_dt=$parse_dt($row['activity_from']??'');
                $to_dt=$parse_dt($row['activity_to']??'');
            }else{
                $date=trim(sanitize_text_field(wp_unslash($row['activity_date']??'')));
                $from_time=trim(sanitize_text_field(wp_unslash($row['from_time']??'')));
                $to_time=trim(sanitize_text_field(wp_unslash($row['to_time']??'')));
                if($date!=='' && $from_time!=='') $from_dt=$parse_dt($date.' '.$from_time);
                if($date!=='' && $to_time!=='') $to_dt=$parse_dt($date.' '.$to_time);
            }
            $minutes_mode=true; // Minutes-only Time Sheet; preserve legacy timestamps for storage compatibility.
            if($minutes_mode){
                $raw_minutes=$row['duration_minutes']??null;
                if(!is_scalar($raw_minutes)||!preg_match('/^[1-9][0-9]*$/',trim((string)$raw_minutes))) wp_send_json_error(['message'=>'Enter positive whole Minutes in row '.($i+1)],422);
                $manual_minutes=(int)$raw_minutes;
                if($manual_minutes>1440) wp_send_json_error(['message'=>'Minutes cannot exceed 1440 in row '.($i+1)],422);
                $date=trim(sanitize_text_field(wp_unslash($row['activity_date']??'')));
                $from_dt=$parse_dt($date.' 00:00');
                if(!$from_dt) wp_send_json_error(['message'=>'Select a valid Date in row '.($i+1)],422);
                $to_dt=clone $from_dt;$to_dt->modify('+'.$manual_minutes.' minutes');
            }
            if(!$from_dt) wp_send_json_error(['message'=>'Select a valid Date / From Time in row '.($i+1)],422);
            if(!$to_dt) wp_send_json_error(['message'=>'Select a valid To Time in row '.($i+1)],422);
            if($to_dt->getTimestamp()<=$from_dt->getTimestamp()) wp_send_json_error(['message'=>'To Time must be later than From Time in row '.($i+1)],422);
            $minutes=$minutes_mode?$manual_minutes:(int)round(($to_dt->getTimestamp()-$from_dt->getTimestamp())/60);
            if($minutes<=0) wp_send_json_error(['message'=>'Activity duration must be at least 1 minute in row '.($i+1)],422);
            if($minutes>1440) wp_send_json_error(['message'=>'Activity duration cannot exceed 24 hours in row '.($i+1)],422);
            $activity_at=$from_dt->format('Y-m-d H:i:s');
            $ended_at=$to_dt->format('Y-m-d H:i:s');

            $row_uid=$uid; $employee_id=$default_employee_id;
            $selected=(int)($row['employee_id']??0);
            if($priv && $selected>0){
                $emp=$wpdb->get_row($wpdb->prepare("SELECT id,wp_user_id FROM {$this->tables['employees']} WHERE id=%d",$selected));
                if(!$emp) wp_send_json_error(['message'=>'Selected Employee was not found in row '.($i+1)],422);
                $employee_id=(int)$emp->id;
                if(!empty($emp->wp_user_id)) $row_uid=(int)$emp->wp_user_id;
            }elseif(!$priv && $default_employee_id>0){
                $employee_id=$default_employee_id;
            }

            $edit_id=(int)($row['id']??0);
            if($edit_id>0){
                $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['timesheet']} WHERE id=%d",$edit_id));
                if(!$existing) wp_send_json_error(['message'=>'Daily Activity record not found'],404);
                $owns=((int)$existing->user_id===$uid)||($default_employee_id>0 && (int)$existing->employee_id===$default_employee_id);
                if(!$priv && !$owns) wp_send_json_error(['message'=>'You can edit only your own Time Sheet activity'],403);
                if(!$priv){
                    $row_uid=(int)$existing->user_id ?: $uid;
                    $employee_id=(int)$existing->employee_id ?: $default_employee_id;
                }
                $ok=$wpdb->update($this->tables['timesheet'],[
                    'user_id'=>$row_uid?:null,'employee_id'=>$employee_id?:null,'workspace_name'=>$workspace_name,'work_area_name'=>$work_area_name,
                    'activity'=>$activity,'remarks'=>$remarks,'duration_minutes'=>$minutes,'entry_mode'=>$minutes_mode?'minutes':'range','activity_at'=>$activity_at,'ended_at'=>$ended_at,'updated_at'=>current_time('mysql')
                ],['id'=>$edit_id],['%d','%d','%s','%s','%s','%s','%d','%s','%s','%s','%s'],['%d']);
                if($ok===false) wp_send_json_error(['message'=>'Could not update Daily Activity'],500);
                $id=$edit_id;
            }else{
                $ok=$wpdb->insert($this->tables['timesheet'],[
                    'user_id'=>$row_uid?:null,
                    'employee_id'=>$employee_id?:null,
                    'workspace_name'=>$workspace_name,
                    'work_area_name'=>$work_area_name,
                    'activity'=>$activity,
                    'remarks'=>$remarks,
                    'duration_minutes'=>$minutes,
                    'entry_mode'=>$minutes_mode?'minutes':'range',
                    'activity_at'=>$activity_at,
                    'ended_at'=>$ended_at,
                    'created_at'=>current_time('mysql'),
                    'updated_at'=>current_time('mysql')
                ],['%d','%d','%s','%s','%s','%s','%d','%s','%s','%s','%s','%s']);
                if($ok===false) wp_send_json_error(['message'=>'Could not save Daily Activity'],500);
                $id=(int)$wpdb->insert_id;
            }
            $r=$wpdb->get_row($wpdb->prepare("SELECT t.*,e.name employee_name,u.display_name user_name FROM {$this->tables['timesheet']} t LEFT JOIN {$this->tables['employees']} e ON e.id=t.employee_id LEFT JOIN {$wpdb->users} u ON u.ID=t.user_id WHERE t.id=%d",$id));
            $saved[]=$this->timesheet_row_state($r);
        }

        wp_send_json_success(['entry'=>$saved[0]??null,'entries'=>$saved]);
    }

    public function ajax_delete_timesheet_entry(){
        $this->verify();
        if((int)get_option('bb_show_module_timesheet',0)!==1) wp_send_json_error(['message'=>'Time Sheet module is disabled'],403);
        global $wpdb;
        $id=(int)($_POST['id']??0);
        if($id<=0) wp_send_json_error(['message'=>'Invalid Daily Activity record'],422);
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['timesheet']} WHERE id=%d",$id));
        if(!$row) wp_send_json_error(['message'=>'Daily Activity record not found'],404);
        if(!$this->is_privileged_user()) wp_send_json_error(['message'=>'Only Owner and Manager can delete Time Sheet records'],403);
        $deleted=$wpdb->delete($this->tables['timesheet'],['id'=>$id],['%d']);
        if($deleted===false) wp_send_json_error(['message'=>'Could not delete Daily Activity'],500);
        wp_send_json_success(['id'=>$id]);
    }

    public function ajax_save_org_settings(){
        $this->verify();
        $this->require_manage();

        $org_name=sanitize_text_field(wp_unslash($_POST['org_name']??''));
        if(mb_strlen($org_name)>150) $org_name=mb_substr($org_name,0,150);

        $mobile=preg_replace('/[^0-9+\- ()]/','',sanitize_text_field(wp_unslash($_POST['org_mobile']??'')));
        if(strlen($mobile)>30) $mobile=substr($mobile,0,30);
        $email=sanitize_email(wp_unslash($_POST['org_email']??''));
        $address=sanitize_textarea_field(wp_unslash($_POST['org_address']??''));
        $city=sanitize_text_field(wp_unslash($_POST['org_city']??''));
        $state=sanitize_text_field(wp_unslash($_POST['org_state']??''));
        $pin=sanitize_text_field(wp_unslash($_POST['org_pin']??''));
        $gstin=strtoupper(preg_replace('/[^A-Za-z0-9]/','',sanitize_text_field(wp_unslash($_POST['org_gstin']??''))));
        $pan=strtoupper(preg_replace('/[^A-Za-z0-9]/','',sanitize_text_field(wp_unslash($_POST['org_pan']??''))));
        if(strlen($gstin)>15) $gstin=substr($gstin,0,15);
        if(strlen($pan)>10) $pan=substr($pan,0,10);
        if(strlen($pin)>12) $pin=substr($pin,0,12);

        $website=trim((string)wp_unslash($_POST['org_website']??''));
        if($website!=='' && !preg_match('~^https?://~i',$website)) $website='https://'.$website;
        $website=$website!=='' ? esc_url_raw($website) : '';

        update_option('bb_org_name',$org_name);
        update_option('bb_org_mobile',$mobile);
        update_option('bb_org_email',$email);
        update_option('bb_org_address',$address);
        update_option('bb_org_city',$city);
        update_option('bb_org_state',$state);
        update_option('bb_org_pin',$pin);
        update_option('bb_org_gstin',$gstin);
        update_option('bb_org_pan',$pan);
        update_option('bb_org_website',$website);

        if(!empty($_FILES['org_logo_file']['name'])){
            if(!empty($_FILES['org_logo_file']['size']) && (int)$_FILES['org_logo_file']['size']>5*1024*1024){
                wp_send_json_error(['message'=>'Organization logo must be 5 MB or smaller.'],422);
            }
            $check=wp_check_filetype_and_ext($_FILES['org_logo_file']['tmp_name'],$_FILES['org_logo_file']['name']);
            $mime=(string)($check['type']??'');
            if(!in_array($mime,['image/png','image/jpeg','image/webp'],true)){
                wp_send_json_error(['message'=>'Use a PNG, JPG/JPEG or WEBP organization logo.'],422);
            }
            $upload=$this->upload_org_logo('org_logo_file');
            if(is_wp_error($upload)) wp_send_json_error(['message'=>$upload->get_error_message()],422);
        }

        $show_workspace_delete_icons=!empty($_POST['show_workspace_delete_icons'])?1:0;
        $show_attendance=!empty($_POST['show_attendance'])?1:0;
        $show_notes=!empty($_POST['show_notes'])?1:0;
        $show_math=!empty($_POST['show_math'])?1:0;
        $show_billing=!empty($_POST['show_billing'])?1:0;
        $show_leave=!empty($_POST['show_leave'])?1:0;
        $show_timesheet=!empty($_POST['show_timesheet'])?1:0;
        $timesheet_minutes_mode=1;
        $show_callspace=!empty($_POST['show_callspace'])?1:0;
        $show_docspace=!empty($_POST['show_docspace'])?1:0;
        $enable_party_share=!empty($_POST['enable_party_share'])?1:0;
        update_option('bb_show_workspace_delete_icons',$show_workspace_delete_icons);
        update_option('bb_show_module_attendance',$show_attendance);
        update_option('bb_show_module_notes',$show_notes);
        update_option('bb_show_module_math',$show_math);
        update_option('bb_show_module_billing',$show_billing);
        update_option('bb_show_module_leave',$show_leave);
        update_option('bb_show_module_timesheet',$show_timesheet);
        update_option('bb_timesheet_minutes_mode',$timesheet_minutes_mode);
        update_option('bb_show_module_callspace',$show_callspace);
        update_option('bb_show_module_docspace',$show_docspace);
        update_option('bb_enable_party_share',$enable_party_share);

        wp_send_json_success([
            'orgName'=>$org_name,
            'logoUrl'=>$this->org_logo_url(),
            'mobile'=>$mobile,
            'email'=>$email,
            'address'=>$address,
            'city'=>$city,
            'state'=>$state,
            'pin'=>$pin,
            'gstin'=>$gstin,
            'pan'=>$pan,
            'website'=>$website,
            'showWorkspaceDeleteIcons'=>$show_workspace_delete_icons,
            'showAttendance'=>$show_attendance,
            'showNotes'=>$show_notes,
            'showMath'=>$show_math,
            'showBilling'=>$show_billing,
            'showLeave'=>$show_leave,
            'showTimeSheet'=>$show_timesheet,
            'timeSheetMinutesMode'=>$timesheet_minutes_mode,
            'showCallSpace'=>$show_callspace,
            'showDocSpace'=>$show_docspace,
            'enablePartyShare'=>$enable_party_share,
        ]);
    }

    public function ajax_save_retention(){
        $this->verify();
        if(!current_user_can('bb_manage_history') && !current_user_can('manage_options')) wp_send_json_error(['message'=>'History retention permission required'],403);
        $months=absint($_POST['months']??12); if($months<1) $months=1; if($months>120) $months=120;
        update_option('bb_history_retention_months',$months); update_option('bb_purge_proof_with_history',!empty($_POST['purge_proof'])?1:0); wp_send_json_success(['months'=>$months]);
    }

    private function archive_history_before($cutoff){
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT DATE_FORMAT(due_date,'%%Y-%%m-01') period_month, task_id, COALESCE(assignee_id,0) assignee_id, COALESCE(workspace_id,0) workspace_id, COALESCE(work_area_id,0) work_area_id,
            COUNT(*) due_count,
            SUM(result='Early') early_count, SUM(result='On Time') on_time_count, SUM(result='Late') late_count, SUM(result='Missed') missed_count, SUM(result='Skipped') skipped_count,
            SUM(score_points) total_points
            FROM {$this->tables['history']} WHERE due_date < %s
            GROUP BY period_month,task_id,assignee_id,workspace_id,work_area_id",$cutoff));
        foreach($rows as $r){
            $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['score_archive']} WHERE period_month=%s AND task_id=%d AND assignee_id=%d AND workspace_id=%d AND work_area_id=%d",
                $r->period_month,$r->task_id,$r->assignee_id,$r->workspace_id,$r->work_area_id));
            $data=['period_month'=>$r->period_month,'task_id'=>$r->task_id,'assignee_id'=>$r->assignee_id,'workspace_id'=>$r->workspace_id,'work_area_id'=>$r->work_area_id,
                'due_count'=>(int)$r->due_count,'early_count'=>(int)$r->early_count,'on_time_count'=>(int)$r->on_time_count,'late_count'=>(int)$r->late_count,
                'missed_count'=>(int)$r->missed_count,'skipped_count'=>(int)$r->skipped_count,'total_points'=>(int)$r->total_points,'updated_at'=>current_time('mysql')];
            if($existing){
                foreach(['due_count','early_count','on_time_count','late_count','missed_count','skipped_count','total_points'] as $f) $data[$f]+=(int)$existing->$f;
                $wpdb->update($this->tables['score_archive'],$data,['id'=>$existing->id]);
            } else $wpdb->insert($this->tables['score_archive'],$data);
        }
    }


    private function purge_proof_attachments_before($cutoff){
        if(!get_option('bb_purge_proof_with_history',0)) return 0;
        global $wpdb; $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tables['attachments']} WHERE attachment_kind='proof' AND occurrence_date IS NOT NULL AND occurrence_date < %s",$cutoff));
        foreach($rows as $r){ wp_delete_attachment($r->attachment_id,true); $wpdb->delete($this->tables['attachments'],['id'=>$r->id]); }
        return count($rows);
    }


    // ---------------------------------------------------------------------
    // MathSpace / Math Area / Math Entry
    // ---------------------------------------------------------------------
    private function hard_repair_mathspaces_table(){
        global $wpdb;
        $table=$this->tables['mathspaces'];
        $tmp=$table.'_repair_'.substr(md5((string)microtime(true)),0,8);
        $bak=$table.'_bak_'.gmdate('YmdHis');
        $charset=$wpdb->get_charset_collate();

        $created=$wpdb->query("CREATE TABLE {$tmp} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(150) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            sort_order int NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY sort_order (sort_order)
        ) {$charset}");
        if($created===false) return false;

        // Preserve every compatible existing MathSpace row and, importantly, its id so
        // existing Math Areas keep pointing to the same MathSpace after the repair.
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))===$table){
            $cols=$wpdb->get_col("SHOW COLUMNS FROM {$table}",0);
            if(is_array($cols) && in_array('id',$cols,true) && in_array('name',$cols,true)){
                $status=in_array('status',$cols,true)?"COALESCE(status,'active')":"'active'";
                $sort=in_array('sort_order',$cols,true)?"COALESCE(sort_order,0)":"0";
                $created_at=in_array('created_at',$cols,true)?"COALESCE(created_at,NOW())":"NOW()";
                $updated_at=in_array('updated_at',$cols,true)?"COALESCE(updated_at,NOW())":"NOW()";
                $wpdb->query("INSERT INTO {$tmp} (id,name,status,sort_order,created_at,updated_at)
                    SELECT id,LEFT(COALESCE(name,''),150),{$status},{$sort},{$created_at},{$updated_at} FROM {$table}");
            }
            $renamed=$wpdb->query("RENAME TABLE {$table} TO {$bak}, {$tmp} TO {$table}");
            if($renamed===false){ $wpdb->query("DROP TABLE IF EXISTS {$tmp}"); return false; }
        }else{
            $renamed=$wpdb->query("RENAME TABLE {$tmp} TO {$table}");
            if($renamed===false){ $wpdb->query("DROP TABLE IF EXISTS {$tmp}"); return false; }
        }
        return true;
    }

    public function ajax_save_mathspace(){
        // v4.14.60: keep MathSpace saving compatible with older/partially migrated
        // site tables. Do not run the full Math table repair on every save; instead
        // inspect the table that actually exists and write only supported columns.
        // This also makes any remaining server/database error visible to the UI.
        try{
            $this->verify(); $this->require_manage(); global $wpdb;
            $id=absint($_POST['id']??0);
            $name=preg_replace('/\s+/u',' ',trim(sanitize_text_field(wp_unslash($_POST['name']??''))));
            $has_assignments=isset($_POST['assignments']);
            $assignment_items=$has_assignments?$this->assignment_items_from_post('assignments'):[];
            if(!$name){ wp_send_json_error(['message'=>'MathSpace name is required']); return; }


            $table=!empty($this->tables['mathspaces']) ? $this->tables['mathspaces'] : $wpdb->prefix.'bb_mathspaces';
            $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table));
            if($exists!==$table){
                $charset=$wpdb->get_charset_collate();
                $made=$wpdb->query("CREATE TABLE {$table} (
                    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                    name varchar(150) NOT NULL,
                    status varchar(20) NOT NULL DEFAULT 'active',
                    sort_order int NOT NULL DEFAULT 0,
                    created_at datetime NULL DEFAULT NULL,
                    updated_at datetime NULL DEFAULT NULL,
                    PRIMARY KEY (id), KEY sort_order (sort_order)
                ) {$charset}");
                if($made===false){
                    wp_send_json_error(['message'=>'Could not create MathSpace table','db_error'=>(string)$wpdb->last_error]); return;
                }
            }

            $column_rows=$wpdb->get_results("SHOW COLUMNS FROM {$table}");
            if(!is_array($column_rows) || !$column_rows){
                wp_send_json_error(['message'=>'Could not read MathSpace table structure','db_error'=>(string)$wpdb->last_error]); return;
            }
            $columns=[]; $column_meta=[];
            foreach($column_rows as $c){ $columns[]=(string)$c->Field; $column_meta[(string)$c->Field]=$c; }

            if(!in_array('name',$columns,true)){
                $added=$wpdb->query("ALTER TABLE {$table} ADD COLUMN name varchar(150) NOT NULL DEFAULT ''");
                if($added===false){
                    wp_send_json_error(['message'=>'MathSpace table is missing the name field','db_error'=>(string)$wpdb->last_error]); return;
                }
                $columns[]='name';
            }

            $needle=$this->normalized_work_area_name($name);
            $duplicate=false;
            $rows=$wpdb->get_results("SELECT id,name FROM {$table}");
            foreach((array)$rows as $row){
                if($id && (int)$row->id===$id) continue;
                if($this->normalized_work_area_name($row->name)===$needle){ $duplicate=true; break; }
            }
            if($duplicate){ wp_send_json_error(['message'=>'This MathSpace already exists.'],409); return; }

            $data=['name'=>$name];
            if(in_array('status',$columns,true)) $data['status']='active';
            if(in_array('updated_at',$columns,true)) $data['updated_at']=current_time('mysql');

            if(!$id){
                if(in_array('sort_order',$columns,true)){
                    $data['sort_order']=((int)$wpdb->get_var("SELECT MAX(sort_order) FROM {$table}"))+10;
                }
                if(in_array('created_at',$columns,true)) $data['created_at']=current_time('mysql');

                // Very old tables may have an id column without AUTO_INCREMENT.
                // Supply the next id explicitly in that case.
                if(in_array('id',$columns,true)){
                    $extra=strtolower((string)($column_meta['id']->Extra??''));
                    if(strpos($extra,'auto_increment')===false){
                        $data['id']=((int)$wpdb->get_var("SELECT MAX(id) FROM {$table}"))+1;
                    }
                }
                $ok=$wpdb->insert($table,$data);
                $saved_id=(int)($data['id']??$wpdb->insert_id);
            }else{
                $ok=$wpdb->update($table,$data,['id'=>$id]);
                $saved_id=$id;
            }

            if($ok===false){
                $err=(string)$wpdb->last_error;
                error_log('Workboard MathSpace save failed: '.$err);
                wp_send_json_error(['message'=>'Could not save MathSpace','db_error'=>$err]); return;
            }
            if($has_assignments){
                $this->save_entity_assignments_internal('mathspace',$saved_id,$assignment_items);
            }
            wp_send_json_success(['mathspace_id'=>$saved_id]);
        }catch(\Throwable $e){
            error_log('Workboard MathSpace fatal: '.$e->getMessage());
            wp_send_json_error([
                'message'=>'MathSpace save failed on the server',
                'server_error'=>$e->getMessage(),
                'server_file'=>basename($e->getFile()),
                'server_line'=>(int)$e->getLine(),
            ]);
        }
    }
    public function ajax_delete_mathspace(){
        $this->verify(); $this->require_manage(); global $wpdb; $id=absint($_POST['id']??0);
        if(!$id) wp_send_json_error(['message'=>'MathSpace not found'],404);
        $area_ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->tables['math_areas']} WHERE mathspace_id=%d",$id)));
        $wpdb->query('START TRANSACTION');
        $ok1=$wpdb->delete($this->tables['math_entries'],['mathspace_id'=>$id]);
        if($area_ids){ foreach($area_ids as $aid) $wpdb->delete($this->tables['entity_assignments'],['entity_type'=>'math_area','entity_id'=>$aid]); }
        $ok2=$wpdb->delete($this->tables['math_areas'],['mathspace_id'=>$id]);
        $wpdb->delete($this->tables['entity_assignments'],['entity_type'=>'mathspace','entity_id'=>$id]);
        $ok3=$wpdb->delete($this->tables['mathspaces'],['id'=>$id]);
        if($ok1===false||$ok2===false||$ok3===false){$wpdb->query('ROLLBACK');wp_send_json_error(['message'=>'Could not delete MathSpace'],500);}
        $wpdb->query('COMMIT'); wp_send_json_success();
    }
    public function ajax_save_math_area(){
        $this->verify(); global $wpdb;
        $this->ensure_math_table_integrity();
        $id=absint($_POST['id']??0); $mid=absint($_POST['mathspace_id']??0); $name=preg_replace('/\s+/u',' ',trim(sanitize_text_field(wp_unslash($_POST['name']??''))));
        $color=sanitize_hex_color($_POST['color']??'')?:'#0ea5e9';
        $show_date=!empty($_POST['show_date'])?1:0; $show_contact_number=!empty($_POST['show_contact_number'])?1:0;
        if(!$mid || !$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['mathspaces']} WHERE id=%d AND status='active'",$mid))) wp_send_json_error(['message'=>'MathSpace not found'],404);
        if(!$name) wp_send_json_error(['message'=>'Math Area name is required'],422);
        $needle=$this->normalized_work_area_name($name);
        $duplicate=false;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT id,name FROM {$this->tables['math_areas']} WHERE mathspace_id=%d",$mid));
        foreach((array)$rows as $row){
            if($id && (int)$row->id===$id) continue;
            if($this->normalized_work_area_name($row->name)===$needle){ $duplicate=true; break; }
        }
        if($duplicate) wp_send_json_error(['message'=>'This Math Area already exists in this MathSpace.'],409);
        if(!$this->is_privileged_user()) wp_send_json_error(['message'=>'Owner or Manager access required to change Math Areas and sharing'],403);
        $has_assignments=isset($_POST['assignments']);
        $assignment_items=$has_assignments?$this->assignment_items_from_post('assignments'):[];
        $data=['mathspace_id'=>$mid,'name'=>$name,'color'=>$color,'show_date'=>$show_date,'show_contact_number'=>$show_contact_number,'updated_at'=>current_time('mysql')];
        if(!$id){$data['sort_order']=((int)$wpdb->get_var($wpdb->prepare("SELECT MAX(sort_order) FROM {$this->tables['math_areas']} WHERE mathspace_id=%d",$mid)))+10;$data['created_at']=current_time('mysql');}
        $ok=$id?$wpdb->update($this->tables['math_areas'],$data,['id'=>$id]):$wpdb->insert($this->tables['math_areas'],$data);
        if($ok===false) wp_send_json_error(['message'=>'Could not save Math Area'],500);
        $aid=(int)($id?:$wpdb->insert_id);
        if($has_assignments) $this->save_entity_assignments_internal('math_area',$aid,$assignment_items);
        wp_send_json_success(['math_area_id'=>$aid]);
    }
    public function ajax_reorder_math_areas(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $mid=absint($_POST['mathspace_id']??0);
        $raw=wp_unslash($_POST['math_area_ids']??'[]');
        $ids=json_decode($raw,true);
        if(!is_array($ids)) $ids=array_filter(array_map('absint',explode(',',sanitize_text_field($raw))));
        $ids=array_values(array_unique(array_filter(array_map('absint',$ids))));
        if(!$mid || !$ids) wp_send_json_error(['message'=>'No Math Areas supplied']);
        $valid=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->tables['math_areas']} WHERE mathspace_id=%d ORDER BY sort_order,id",$mid)));
        if(count($valid)!==count($ids) || array_diff($valid,$ids) || array_diff($ids,$valid)) wp_send_json_error(['message'=>'Invalid Math Area order'],400);
        $wpdb->query('START TRANSACTION');
        foreach($ids as $i=>$id){
            if($wpdb->update($this->tables['math_areas'],['sort_order'=>(($i+1)*10),'updated_at'=>current_time('mysql')],['id'=>$id,'mathspace_id'=>$mid])===false){
                $wpdb->query('ROLLBACK'); wp_send_json_error(['message'=>'Could not save Math Area order'],500);
            }
        }
        $wpdb->query('COMMIT');
        wp_send_json_success(['math_area_ids'=>$ids]);
    }


    public function ajax_reorder_math_entries(){
        $this->verify(); global $wpdb;
        $aid=absint($_POST['math_area_id']??0);
        $area=$aid?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['math_areas']} WHERE id=%d",$aid)):null;
        if(!$area) wp_send_json_error(['message'=>'Math Area not found'],404);
        if(!$this->math_area_permission($aid,'edit')) wp_send_json_error(['message'=>'You do not have EDIT permission for this Math Area'],403);
        $raw=wp_unslash($_POST['math_entry_ids']??'[]');
        $ids=json_decode($raw,true); if(!is_array($ids)) $ids=[];
        $ids=array_values(array_unique(array_filter(array_map('absint',$ids))));
        if(!$ids) wp_send_json_error(['message'=>'No entries supplied'],422);
        $valid=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->tables['math_entries']} WHERE math_area_id=%d ORDER BY sort_order,id",$aid)));
        if(count($valid)!==count($ids) || array_diff($valid,$ids) || array_diff($ids,$valid)) wp_send_json_error(['message'=>'Invalid entry order'],400);
        $wpdb->query('START TRANSACTION');
        foreach($ids as $i=>$id){
            if($wpdb->update($this->tables['math_entries'],['sort_order'=>(($i+1)*10),'updated_at'=>current_time('mysql')],['id'=>$id,'math_area_id'=>$aid])===false){
                $wpdb->query('ROLLBACK'); wp_send_json_error(['message'=>'Could not save entry order'],500);
            }
        }
        $wpdb->query('COMMIT'); wp_send_json_success();
    }

    public function ajax_delete_math_area(){
        $this->verify(); global $wpdb; $id=absint($_POST['id']??0);
        if(!$id) wp_send_json_error(['message'=>'Math Area not found'],404);
        if(!$this->is_privileged_user()) wp_send_json_error(['message'=>'Owner or Manager access required to delete a Math Area'],403);
        $entry_count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['math_entries']} WHERE math_area_id=%d",$id));
        if($entry_count>0) wp_send_json_error(['message'=>'Delete is available only when this Math Area has no Particular entries.'],422);
        $wpdb->query('START TRANSACTION');
        $ok1=$wpdb->delete($this->tables['entity_assignments'],['entity_type'=>'math_area','entity_id'=>$id]);
        $ok2=$wpdb->delete($this->tables['math_areas'],['id'=>$id]);
        if($ok1===false||$ok2===false){$wpdb->query('ROLLBACK');wp_send_json_error(['message'=>'Could not delete Math Area'],500);}
        $wpdb->query('COMMIT'); wp_send_json_success();
    }

    public function ajax_save_math_entry(){
        $this->verify(); global $wpdb;
        $this->ensure_math_table_integrity(); $id=absint($_POST['id']??0); $aid=absint($_POST['math_area_id']??0);
        $area=$aid?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['math_areas']} WHERE id=%d",$aid)):null;
        if(!$area) wp_send_json_error(['message'=>'Math Area not found'],404);
        if($id){
            $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['math_entries']} WHERE id=%d",$id));
            if(!$existing || (int)$existing->math_area_id!==$aid || (int)$existing->mathspace_id!==(int)$area->mathspace_id) wp_send_json_error(['message'=>'Entry not found in this Math Area'],404);
            if(!$this->math_area_permission((int)$existing->math_area_id,'edit')) wp_send_json_error(['message'=>'You do not have EDIT permission for this Math Area'],403);
        }elseif(!$this->math_area_permission($aid,'add')){
            wp_send_json_error(['message'=>'You do not have ADD permission for this Math Area'],403);
        }
        $particular=sanitize_text_field(wp_unslash($_POST['particular']??''));
        $entry_date=''; if(!empty($area->show_date)){ $raw_date=sanitize_text_field(wp_unslash($_POST['entry_date']??'')); if($raw_date && preg_match('/^\d{4}-\d{2}-\d{2}$/',$raw_date)) $entry_date=$raw_date; }
        $number=!empty($area->show_contact_number)?substr(preg_replace('/\D+/','',(string)wp_unslash($_POST['number']??'')),0,15):''; $amount=round((float)($_POST['amount']??0),2);
        if(!$particular) wp_send_json_error(['message'=>'Particular is required'],422);
        $data=['mathspace_id'=>(int)$area->mathspace_id,'math_area_id'=>$aid,'particular'=>$particular,'entry_date'=>($entry_date?:null),'number'=>$number,'amount'=>$amount,'updated_at'=>current_time('mysql')];
        if(!$id){$data['sort_order']=((int)$wpdb->get_var($wpdb->prepare("SELECT MAX(sort_order) FROM {$this->tables['math_entries']} WHERE math_area_id=%d",$aid)))+10;$data['created_at']=current_time('mysql');}
        $ok=$id?$wpdb->update($this->tables['math_entries'],$data,['id'=>$id]):$wpdb->insert($this->tables['math_entries'],$data);
        if($ok===false) wp_send_json_error(['message'=>'Could not save entry'],500);
        wp_send_json_success(['math_entry_id'=>(int)($id?:$wpdb->insert_id)]);
    }
    public function ajax_bulk_upload_math_entries(){
        $this->verify(); global $wpdb;
        $aid=absint($_POST['math_area_id']??0);
        $area=$aid?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['math_areas']} WHERE id=%d",$aid)):null;
        if(!$area) wp_send_json_error(['message'=>'Math Area not found'],404);
        if(!$this->math_area_permission($aid,'add')) wp_send_json_error(['message'=>'You do not have ADD permission for this Math Area'],403);
        $rows=json_decode(wp_unslash($_POST['rows']??'[]'),true);
        if(!is_array($rows) || !$rows) wp_send_json_error(['message'=>'No rows found in template'],422);
        if(count($rows)>1000) wp_send_json_error(['message'=>'Maximum 1000 rows can be uploaded at one time'],422);
        $sort=((int)$wpdb->get_var($wpdb->prepare("SELECT MAX(sort_order) FROM {$this->tables['math_entries']} WHERE math_area_id=%d",$aid)))+10;
        $inserted=0;$skipped=0;$now=current_time('mysql');
        $wpdb->query('START TRANSACTION');
        foreach($rows as $r){
            $particular=sanitize_text_field((string)($r['particular']??''));
            $entry_date=''; if(!empty($area->show_date)){ $raw_date=sanitize_text_field((string)($r['entry_date']??'')); if($raw_date && preg_match('/^\d{4}-\d{2}-\d{2}$/',$raw_date)) $entry_date=$raw_date; }
            $number=!empty($area->show_contact_number)?substr(preg_replace('/\D+/','',(string)($r['number']??'')),0,15):'';
            $amount_raw=str_replace([',','₹',' '],'',(string)($r['amount']??''));
            if($particular==='' || $amount_raw==='' || !is_numeric($amount_raw)){ $skipped++; continue; }
            $amount=round((float)$amount_raw,2);
            $ok=$wpdb->insert($this->tables['math_entries'],[
                'mathspace_id'=>(int)$area->mathspace_id,'math_area_id'=>$aid,'particular'=>$particular,'entry_date'=>($entry_date?:null),'number'=>$number,'amount'=>$amount,
                'sort_order'=>$sort,'created_at'=>$now,'updated_at'=>$now
            ]);
            if($ok===false){$wpdb->query('ROLLBACK');wp_send_json_error(['message'=>'Upload failed while saving rows'],500);}
            $inserted++;$sort+=10;
        }
        if(!$inserted){$wpdb->query('ROLLBACK');wp_send_json_error(['message'=>'No valid rows found. Particular and Amount are required.'],422);}
        $wpdb->query('COMMIT');
        wp_send_json_success(['inserted'=>$inserted,'skipped'=>$skipped]);
    }

    public function ajax_duplicate_math_entry(){
        $this->verify(); global $wpdb;
        $id=absint($_POST['id']??0);
        $entry=$id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['math_entries']} WHERE id=%d",$id)):null;
        if(!$entry) wp_send_json_error(['message'=>'Entry not found'],404);
        if(!$this->math_area_permission((int)$entry->math_area_id,'add')) wp_send_json_error(['message'=>'You do not have ADD permission for this Math Area'],403);
        $rows=$wpdb->get_results($wpdb->prepare("SELECT id,sort_order FROM {$this->tables['math_entries']} WHERE math_area_id=%d ORDER BY sort_order,id",$entry->math_area_id));
        $order=[]; foreach($rows as $r) $order[]=(int)$r->id;
        $pos=array_search((int)$entry->id,$order,true); if($pos===false) $pos=count($order)-1;
        $wpdb->query('START TRANSACTION');
        for($i=count($order)-1;$i>$pos;$i--){
            $wpdb->update($this->tables['math_entries'],['sort_order'=>(($i+2)*10)],['id'=>$order[$i]]);
        }
        $now=current_time('mysql');
        $ok=$wpdb->insert($this->tables['math_entries'],[
            'mathspace_id'=>(int)$entry->mathspace_id,'math_area_id'=>(int)$entry->math_area_id,'particular'=>$entry->particular,'entry_date'=>$entry->entry_date?:null,'number'=>$entry->number,'amount'=>$entry->amount,'sort_order'=>(($pos+2)*10),'created_at'=>$now,'updated_at'=>$now
        ]);
        if($ok===false){$wpdb->query('ROLLBACK');wp_send_json_error(['message'=>'Could not copy entry'],500);}
        $wpdb->query('COMMIT');
        wp_send_json_success(['math_entry_id'=>(int)$wpdb->insert_id]);
    }

    public function ajax_move_math_entry_area(){
        $this->verify(); global $wpdb;
        $id=absint($_POST['id']??0); $dest_id=absint($_POST['math_area_id']??0);
        $entry=$id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['math_entries']} WHERE id=%d",$id)):null;
        $dest=$dest_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['math_areas']} WHERE id=%d",$dest_id)):null;
        if(!$entry||!$dest) wp_send_json_error(['message'=>'Entry or destination Math Area not found'],404);
        if((int)$entry->mathspace_id!==(int)$dest->mathspace_id) wp_send_json_error(['message'=>'Entry can move only within the same MathSpace'],422);
        if(!$this->math_area_permission((int)$entry->math_area_id,'edit') || !$this->math_area_permission((int)$dest_id,'add')) wp_send_json_error(['message'=>'Moving an entry requires EDIT permission on the source Area and ADD permission on the destination Area'],403);
        if((int)$entry->math_area_id===(int)$dest_id) wp_send_json_success();
        $next=((int)$wpdb->get_var($wpdb->prepare("SELECT MAX(sort_order) FROM {$this->tables['math_entries']} WHERE math_area_id=%d",$dest_id)))+10;
        $data=['math_area_id'=>$dest_id,'mathspace_id'=>(int)$dest->mathspace_id,'sort_order'=>$next,'updated_at'=>current_time('mysql')];
        if(empty($dest->show_date)) $data['entry_date']=null;
        if(empty($dest->show_contact_number)) $data['number']='';
        if($wpdb->update($this->tables['math_entries'],$data,['id'=>$id])===false) wp_send_json_error(['message'=>'Could not move entry'],500);
        wp_send_json_success();
    }

    public function ajax_delete_math_entry(){
        $this->verify(); global $wpdb; $id=absint($_POST['id']??0);
        $entry=$id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['math_entries']} WHERE id=%d",$id)):null;
        if(!$entry) wp_send_json_error(['message'=>'Entry not found'],404);
        if(!$this->math_area_permission((int)$entry->math_area_id,'delete')) wp_send_json_error(['message'=>'You do not have DELETE permission for this Math Area'],403);
        if($wpdb->delete($this->tables['math_entries'],['id'=>$id])===false) wp_send_json_error(['message'=>'Could not delete entry'],500);
        wp_send_json_success();
    }

    // Notespace / Note Area / Note Card
    // ---------------------------------------------------------------------

    private function next_notespace_sort_order(){
        global $wpdb;
        return ((int)$wpdb->get_var("SELECT MAX(sort_order) FROM {$this->tables['notespaces']}"))+10;
    }

    private function next_note_area_sort_order($notespace_id){
        global $wpdb;
        return ((int)$wpdb->get_var($wpdb->prepare("SELECT MAX(sort_order) FROM {$this->tables['note_areas']} WHERE notespace_id=%d",$notespace_id)))+10;
    }

    private function next_note_card_sort_order($note_area_id){
        global $wpdb;
        return ((int)$wpdb->get_var($wpdb->prepare("SELECT MAX(sort_order) FROM {$this->tables['note_cards']} WHERE note_area_id=%d",$note_area_id)))+10;
    }

    private function unique_notespace_slug($name,$exclude_id=0){
        global $wpdb;
        $base=sanitize_title($name);
        if(!$base) $base='notespace';
        $slug=$base; $i=2;
        while($wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['notespaces']} WHERE slug=%s AND id<>%d LIMIT 1",$slug,$exclude_id))){
            $slug=$base.'-'.$i; $i++;
        }
        return $slug;
    }

    private function absolute_note_preview_url($candidate,$base_url){
        $candidate=trim(html_entity_decode((string)$candidate,ENT_QUOTES|ENT_HTML5,'UTF-8'));
        if(!$candidate) return '';
        if(preg_match('#^https?://#i',$candidate)) return esc_url_raw($candidate);
        $p=wp_parse_url($base_url);
        if(empty($p['scheme'])||empty($p['host'])) return '';
        if(strpos($candidate,'//')===0) return esc_url_raw($p['scheme'].':'.$candidate);
        $root=$p['scheme'].'://'.$p['host'].(!empty($p['port'])?':'.$p['port']:'');
        if(strpos($candidate,'/')===0) return esc_url_raw($root.$candidate);
        $path=$p['path']??'/';
        $dir=preg_replace('#/[^/]*$#','/',$path);
        return esc_url_raw($root.$dir.$candidate);
    }

    private function note_youtube_video_id($url){
        $p=wp_parse_url((string)$url);
        if(empty($p['host'])) return '';
        $host=strtolower(preg_replace('/^www\./i','',(string)$p['host']));
        $path=trim((string)($p['path']??''),'/');
        $parts=$path===''?[]:explode('/',$path);
        $id='';
        if($host==='youtu.be') $id=$parts[0]??'';
        elseif(in_array($host,['youtube.com','m.youtube.com','music.youtube.com'],true)){
            if(!empty($p['query'])){parse_str($p['query'],$q);$id=(string)($q['v']??'');}
            if(!$id && in_array((string)($parts[0]??''),['shorts','embed','live'],true)) $id=(string)($parts[1]??'');
        }
        $id=trim($id);
        return preg_match('/^[A-Za-z0-9_-]{6,}$/',$id)?$id:'';
    }

    private function note_link_preview($url){
        $url=esc_url_raw(trim((string)$url));
        $fallback=['url'=>$url,'title'=>'','description'=>'','thumbnail'=>''];
        if(!$url || !wp_http_validate_url($url)) return $fallback;
        $res=wp_safe_remote_get($url,[
            'timeout'=>8,
            'redirection'=>3,
            'limit_response_size'=>1048576,
            'user-agent'=>'Workboard/'.self::VERSION.'; '.home_url('/')
        ]);
        if(is_wp_error($res)) return $fallback;
        $body=(string)wp_remote_retrieve_body($res);
        if(!$body) return $fallback;
        $pick=function($names) use ($body){
            foreach((array)$names as $name){
                $n=preg_quote($name,'#');
                if(preg_match('#<meta[^>]+(?:property|name)\s*=\s*["\']'.$n.'["\'][^>]+content\s*=\s*["\']([^"\']+)["\'][^>]*>#is',$body,$m)
                    || preg_match('#<meta[^>]+content\s*=\s*["\']([^"\']+)["\'][^>]+(?:property|name)\s*=\s*["\']'.$n.'["\'][^>]*>#is',$body,$m)){
                    return trim(wp_strip_all_tags(html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8')));
                }
            }
            return '';
        };
        $title=$pick(['og:title','twitter:title']);
        if(!$title && preg_match('#<title[^>]*>(.*?)</title>#is',$body,$m)) $title=trim(wp_strip_all_tags(html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8')));
        $description=$pick(['og:description','twitter:description','description']);
        $image=$pick(['og:image','twitter:image','twitter:image:src']);
        $image=$this->absolute_note_preview_url($image,$url);
        $youtube_id=$this->note_youtube_video_id($url);
        if($youtube_id) $image='https://i.ytimg.com/vi/'.rawurlencode($youtube_id).'/hqdefault.jpg';
        return [
            'url'=>$url,
            'title'=>substr(sanitize_text_field($title),0,300),
            'description'=>substr(sanitize_text_field($description),0,500),
            'thumbnail'=>$image
        ];
    }

    public function ajax_save_notespace(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $id=absint($_POST['id']??0);
        $name=preg_replace('/\s+/u',' ',trim(sanitize_text_field(wp_unslash($_POST['name']??''))));
        $description=sanitize_text_field($_POST['description']??'');
        $color=sanitize_hex_color($_POST['color']??'#7c3aed')?:'#7c3aed';
        $has_assignments=isset($_POST['assignments']);
        $assignment_items=$has_assignments?$this->assignment_items_from_post('assignments'):[];
        if(!$name) wp_send_json_error(['message'=>'Notespace name is required'],422);
        $needle=$this->normalized_work_area_name($name);
        $duplicate=false;
        $rows=$wpdb->get_results("SELECT id,name FROM {$this->tables['notespaces']}");
        foreach((array)$rows as $row){
            if($id && (int)$row->id===$id) continue;
            if($this->normalized_work_area_name($row->name)===$needle){ $duplicate=true; break; }
        }
        if($duplicate) wp_send_json_error(['message'=>'This Notespace already exists.'],409);
        $data=[
            'name'=>$name,'slug'=>$this->unique_notespace_slug($name,$id),
            'description'=>$description,'color'=>$color,'status'=>'active','updated_at'=>current_time('mysql')
        ];
        if(!$id){$data['sort_order']=$this->next_notespace_sort_order();$data['created_at']=current_time('mysql');}
        $ok=$id?$wpdb->update($this->tables['notespaces'],$data,['id'=>$id]):$wpdb->insert($this->tables['notespaces'],$data);
        if($ok===false) wp_send_json_error(['message'=>'Could not save Notespace'],500);
        $saved_id=(int)($id?:$wpdb->insert_id);
        if($has_assignments) $this->save_entity_assignments_internal('notespace',$saved_id,$assignment_items);
        wp_send_json_success(['notespace_id'=>$saved_id]);
    }

    public function ajax_delete_notespace(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $id=absint($_POST['id']??0);
        if(!$id) wp_send_json_error(['message'=>'Notespace not found'],404);
        $wpdb->query('START TRANSACTION');
        $area_ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->tables['note_areas']} WHERE notespace_id=%d",$id)));
        if($area_ids){
            $wpdb->query("DELETE FROM {$this->tables['note_cards']} WHERE note_area_id IN (".implode(',',$area_ids).")");
            foreach($area_ids as $aid) $wpdb->delete($this->tables['entity_assignments'],['entity_type'=>'note_area','entity_id'=>$aid]);
        }
        $ok1=$wpdb->delete($this->tables['note_areas'],['notespace_id'=>$id]);
        $ok2=$wpdb->delete($this->tables['folder_notespaces'],['notespace_id'=>$id]);
        $ok3=$wpdb->delete($this->tables['entity_assignments'],['entity_type'=>'notespace','entity_id'=>$id]);
        $ok4=$wpdb->delete($this->tables['notespaces'],['id'=>$id]);
        if($ok1===false||$ok2===false||$ok3===false||$ok4===false){$wpdb->query('ROLLBACK');wp_send_json_error(['message'=>'Could not delete Notespace'],500);}
        $wpdb->query('COMMIT');
        wp_send_json_success();
    }

    public function ajax_reorder_notespaces(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $raw=wp_unslash($_POST['notespace_ids']??'[]');
        $ids=json_decode($raw,true); if(!is_array($ids)) $ids=[];
        $ids=array_values(array_unique(array_filter(array_map('absint',$ids))));
        foreach($ids as $i=>$id) $wpdb->update($this->tables['notespaces'],['sort_order'=>$i],['id'=>$id]);
        wp_send_json_success();
    }

    public function ajax_toggle_notespace_pin(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $id=absint($_POST['id']??0);
        $pin=!empty($_POST['pin']);
        if(!$id || !$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['notespaces']} WHERE id=%d AND status='active' LIMIT 1",$id))) wp_send_json_error(['message'=>'Notespace not found'],404);
        $ids=array_values(array_unique(array_filter(array_map('absint',(array)get_option('bb_pinned_notespace_ids',[])))));
        $ids=array_values(array_diff($ids,[$id]));
        if($pin) array_unshift($ids,$id);
        update_option('bb_pinned_notespace_ids',$ids,false);
        wp_send_json_success(['id'=>$id,'is_pinned'=>$pin?1:0]);
    }

    public function ajax_reorder_note_areas(){
        $this->verify(); global $wpdb;
        $notespace_id=absint($_POST['notespace_id']??0);
        if(!$notespace_id || !$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['notespaces']} WHERE id=%d",$notespace_id))) wp_send_json_error(['message'=>'Notespace not found'],404);
        if(!$this->is_privileged_user()) wp_send_json_error(['message'=>'Owner or Manager access required to reorder Note Areas'],403);
        $raw=wp_unslash($_POST['note_area_ids']??'[]');
        $ids=json_decode($raw,true); if(!is_array($ids)) $ids=[];
        $ids=array_values(array_unique(array_filter(array_map('absint',$ids))));
        if(!$ids) wp_send_json_error(['message'=>'No Note Areas supplied'],422);
        $valid=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->tables['note_areas']} WHERE notespace_id=%d ORDER BY sort_order,id",$notespace_id)));
        $valid_map=array_fill_keys($valid,true);
        foreach($ids as $id){ if(empty($valid_map[$id])) wp_send_json_error(['message'=>'A Note Area does not belong to this Notespace'],422); }
        foreach($ids as $i=>$id) $wpdb->update($this->tables['note_areas'],['sort_order'=>$i],['id'=>$id,'notespace_id'=>$notespace_id]);
        wp_send_json_success();
    }

    public function ajax_save_note_area(){
        $this->verify(); global $wpdb;
        $id=absint($_POST['id']??0); $notespace_id=absint($_POST['notespace_id']??0);
        $name=preg_replace('/\s+/u',' ',trim(sanitize_text_field(wp_unslash($_POST['name']??''))));
        $description=sanitize_text_field($_POST['description']??'');
        $color=sanitize_hex_color($_POST['color']??'#7c3aed')?:'#7c3aed';
        $date_applicable=!empty($_POST['date_applicable']) && (string)$_POST['date_applicable']!=='0' ? 1 : 0;

        if(!$notespace_id || !$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['notespaces']} WHERE id=%d",$notespace_id))) wp_send_json_error(['message'=>'Notespace not found'],404);

        if(!$this->is_privileged_user()) wp_send_json_error(['message'=>'Owner or Manager access required to change Note Areas and sharing'],403);

        if(!$name) wp_send_json_error(['message'=>'Note Area name is required'],422);
        $needle=$this->normalized_work_area_name($name);
        $duplicate=false;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT id,name FROM {$this->tables['note_areas']} WHERE notespace_id=%d",$notespace_id));
        foreach((array)$rows as $row){
            if($id && (int)$row->id===$id) continue;
            if($this->normalized_work_area_name($row->name)===$needle){ $duplicate=true; break; }
        }
        if($duplicate) wp_send_json_error(['message'=>'This Note Area already exists in this Notespace.'],409);

        $has_assignments=isset($_POST['assignments']);
        $assignment_items=$has_assignments?$this->assignment_items_from_post('assignments'):[];

        $data=['notespace_id'=>$notespace_id,'name'=>$name,'description'=>$description,'color'=>$color,'date_applicable'=>$date_applicable,'applicable_date'=>null,'updated_at'=>current_time('mysql')];
        if(!$id){$data['sort_order']=$this->next_note_area_sort_order($notespace_id);$data['created_at']=current_time('mysql');}

        $ok=$id?$wpdb->update($this->tables['note_areas'],$data,['id'=>$id]):$wpdb->insert($this->tables['note_areas'],$data);
        if($ok===false) wp_send_json_error(['message'=>'Could not save Note Area'],500);

        $note_area_id=(int)($id?:$wpdb->insert_id);
        if($has_assignments) $this->save_entity_assignments_internal('note_area',$note_area_id,$assignment_items);

        wp_send_json_success(['note_area_id'=>$note_area_id]);
    }

    public function ajax_delete_note_area(){
        $this->verify(); global $wpdb;
        $id=absint($_POST['id']??0);
        $area=$id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['note_areas']} WHERE id=%d",$id)):null;
        if(!$area) wp_send_json_error(['message'=>'Note Area not found'],404);
        if(!$this->is_privileged_user()) wp_send_json_error(['message'=>'Owner or Manager access required to delete a Note Area'],403);
        $wpdb->query('START TRANSACTION');
        $ok1=$wpdb->delete($this->tables['note_cards'],['note_area_id'=>$id]);
        $wpdb->delete($this->tables['entity_assignments'],['entity_type'=>'note_area','entity_id'=>$id]);
        $ok2=$wpdb->delete($this->tables['note_areas'],['id'=>$id]);
        if($ok1===false||$ok2===false){$wpdb->query('ROLLBACK');wp_send_json_error(['message'=>'Could not delete Note Area'],500);}
        $wpdb->query('COMMIT');
        wp_send_json_success();
    }

    public function ajax_save_note_card(){
        $this->verify(); global $wpdb;
        $id=absint($_POST['id']??0);
        $note_area_id=absint($_POST['note_area_id']??0);
        $area=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['note_areas']} WHERE id=%d",$note_area_id));
        if(!$area) wp_send_json_error(['message'=>'Note Area not found'],404);
        if($id){
            $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['note_cards']} WHERE id=%d",$id));
            if(!$existing || (int)$existing->note_area_id!==$note_area_id || (int)$existing->notespace_id!==(int)$area->notespace_id) wp_send_json_error(['message'=>'Note Card not found in this Note Area'],404);
            if(!$this->note_area_permission((int)$existing->note_area_id,'edit')) wp_send_json_error(['message'=>'You do not have EDIT permission for this Note Area'],403);
        }elseif(!$this->note_area_permission($note_area_id,'add')){
            wp_send_json_error(['message'=>'You do not have ADD permission for this Note Area'],403);
        }
        $type=sanitize_key($_POST['note_type']??'text');
        $title=sanitize_text_field(wp_unslash($_POST['title']??''));
        if(!$title) wp_send_json_error(['message'=>'Title is required'],422);
        $note_date=sanitize_text_field($_POST['note_date']??'');
        if(!empty($area->date_applicable)){
            if(!$note_date) wp_send_json_error(['message'=>'Date is required for Notes in this Note Area'],422);
            $d=DateTime::createFromFormat('Y-m-d',$note_date);
            if(!$d || $d->format('Y-m-d')!==$note_date) wp_send_json_error(['message'=>'Please enter a valid Date'],422);
        }else{
            $note_date='';
        }
        $text=sanitize_textarea_field(wp_unslash($_POST['text_content']??''));
        $url=esc_url_raw(trim((string)wp_unslash($_POST['url']??'')));
        if($type==='link' || ($url && wp_http_validate_url($url))){
            $type='link';
            if(!$url) wp_send_json_error(['message'=>'Please enter a Note or provide a Link.'],422);
            if(!wp_http_validate_url($url)) wp_send_json_error(['message'=>'Enter a valid link'],422);
            $preview=$this->note_link_preview($url);
            $data=[
                'notespace_id'=>(int)$area->notespace_id,'note_area_id'=>$note_area_id,'note_type'=>'link',
                'title'=>$title,'text_content'=>$text,'url'=>$preview['url'],'link_title'=>$preview['title'],
                'link_description'=>$preview['description'],'thumbnail_url'=>$preview['thumbnail'],'note_date'=>$note_date?:null,
                'updated_at'=>current_time('mysql')
            ];
        }else{
            $type='text';
            if(!$text) wp_send_json_error(['message'=>'Please enter a Note or provide a Link.'],422);
            $data=[
                'notespace_id'=>(int)$area->notespace_id,'note_area_id'=>$note_area_id,'note_type'=>'text',
                'title'=>$title,'text_content'=>$text,'url'=>'','link_title'=>'','link_description'=>'','thumbnail_url'=>'','note_date'=>$note_date?:null,
                'updated_at'=>current_time('mysql')
            ];
        }
        if(!$id){$data['sort_order']=$this->next_note_card_sort_order($note_area_id);$data['created_at']=current_time('mysql');}
        $ok=$id?$wpdb->update($this->tables['note_cards'],$data,['id'=>$id]):$wpdb->insert($this->tables['note_cards'],$data);
        if($ok===false) wp_send_json_error(['message'=>'Could not save Note Card'],500);
        wp_send_json_success(['note_card_id'=>(int)($id?:$wpdb->insert_id)]);
    }


    public function ajax_duplicate_note_card(){
        $this->verify(); global $wpdb;
        $id=absint($_POST['id']??0);
        if(!$id) wp_send_json_error(['message'=>'Note Card not found'],404);
        $card=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['note_cards']} WHERE id=%d",$id));
        if(!$card) wp_send_json_error(['message'=>'Note Card not found'],404);
        if(!$this->note_area_permission((int)$card->note_area_id,'add')) wp_send_json_error(['message'=>'You do not have ADD permission for this Note Area'],403);

        $wpdb->query('START TRANSACTION');
        $siblings=array_map('intval',$wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$this->tables['note_cards']} WHERE note_area_id=%d ORDER BY sort_order ASC,id ASC",$card->note_area_id
        )));
        $insert_index=array_search((int)$id,$siblings,true);
        if($insert_index===false) $insert_index=count($siblings)-1;

        $copy=(array)$card;
        unset($copy['id']);
        $copy['sort_order']=($insert_index+1)*10;
        $copy['created_at']=current_time('mysql');
        $copy['updated_at']=current_time('mysql');
        if($wpdb->insert($this->tables['note_cards'],$copy)===false){
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message'=>'Could not copy Note Card'],500);
        }
        $new_id=(int)$wpdb->insert_id;
        array_splice($siblings,$insert_index+1,0,[$new_id]);
        foreach($siblings as $i=>$nid){
            if($wpdb->update($this->tables['note_cards'],['sort_order'=>$i*10],['id'=>$nid,'note_area_id'=>$card->note_area_id])===false){
                $wpdb->query('ROLLBACK');
                wp_send_json_error(['message'=>'Could not place copied Note Card'],500);
            }
        }
        $wpdb->query('COMMIT');
        wp_send_json_success(['note_card_id'=>$new_id]);
    }

    public function ajax_delete_note_card(){
        $this->verify(); global $wpdb;
        $id=absint($_POST['id']??0);
        $card=$id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['note_cards']} WHERE id=%d",$id)):null;
        if(!$card) wp_send_json_error(['message'=>'Note Card not found'],404);
        if(!$this->note_area_permission((int)$card->note_area_id,'delete')) wp_send_json_error(['message'=>'You do not have DELETE permission for this Note Area'],403);
        if($wpdb->delete($this->tables['note_cards'],['id'=>$id])===false) wp_send_json_error(['message'=>'Could not delete Note Card'],500);
        wp_send_json_success();
    }

    public function ajax_reorder_note_cards(){
        $this->verify(); global $wpdb;
        $area_id=absint($_POST['note_area_id']??0);
        $area=$area_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['note_areas']} WHERE id=%d",$area_id)):null;
        if(!$area) wp_send_json_error(['message'=>'Note Area not found'],404);
        if(!$this->note_area_permission($area_id,'edit')) wp_send_json_error(['message'=>'You do not have EDIT permission for this Note Area'],403);
        $raw=wp_unslash($_POST['note_card_ids']??'[]');
        $ids=json_decode($raw,true); if(!is_array($ids)) $ids=[];
        $ids=array_values(array_unique(array_filter(array_map('absint',$ids))));
        foreach($ids as $i=>$id){
            $wpdb->update($this->tables['note_cards'],['sort_order'=>$i],['id'=>$id,'note_area_id'=>$area_id]);
        }
        wp_send_json_success();
    }

    public function ajax_move_note_card_area(){
        $this->verify(); global $wpdb;
        $card_id=absint($_POST['note_card_id']??0);
        $dest_area_id=absint($_POST['note_area_id']??0);
        $card=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['note_cards']} WHERE id=%d",$card_id));
        $dest=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['note_areas']} WHERE id=%d",$dest_area_id));
        if(!$card||!$dest) wp_send_json_error(['message'=>'Note Card or Note Area not found'],404);
        if((int)$card->notespace_id!==(int)$dest->notespace_id) wp_send_json_error(['message'=>'A Note Card can only move within its Notespace'],422);
        if(!$this->note_area_permission((int)$card->note_area_id,'edit') || !$this->note_area_permission($dest_area_id,'add')) wp_send_json_error(['message'=>'Moving a Note requires EDIT permission on the source Area and ADD permission on the destination Area'],403);
        $wpdb->update($this->tables['note_cards'],[
            'note_area_id'=>$dest_area_id,'sort_order'=>$this->next_note_card_sort_order($dest_area_id),'updated_at'=>current_time('mysql')
        ],['id'=>$card_id]);
        $raw=wp_unslash($_POST['note_card_ids']??'[]');
        $ids=json_decode($raw,true); if(!is_array($ids)) $ids=[];
        foreach(array_values(array_unique(array_filter(array_map('absint',$ids)))) as $i=>$id){
            $wpdb->update($this->tables['note_cards'],['sort_order'=>$i],['id'=>$id,'note_area_id'=>$dest_area_id]);
        }
        wp_send_json_success();
    }

    public function ajax_assign_folder_contents(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $folder_id=absint($_POST['folder_id']??0);
        if(!$folder_id || !$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['folders']} WHERE id=%d",$folder_id))) wp_send_json_error(['message'=>'Folder not found'],404);
        $workspace_ids=json_decode(wp_unslash($_POST['workspace_ids']??'[]'),true);
        $notespace_ids=json_decode(wp_unslash($_POST['notespace_ids']??'[]'),true);
        if(!is_array($workspace_ids)) $workspace_ids=[];
        if(!is_array($notespace_ids)) $notespace_ids=[];
        $workspace_ids=array_values(array_unique(array_filter(array_map('absint',$workspace_ids))));
        $notespace_ids=array_values(array_unique(array_filter(array_map('absint',$notespace_ids))));
        $valid_ws=$workspace_ids?array_map('intval',$wpdb->get_col("SELECT id FROM {$this->tables['workspaces']} WHERE status NOT IN ('trash','archived') AND id IN (".implode(',',$workspace_ids).")")):[];
        $valid_ns=$notespace_ids?array_map('intval',$wpdb->get_col("SELECT id FROM {$this->tables['notespaces']} WHERE status='active' AND id IN (".implode(',',$notespace_ids).")")):[];
        $old_ws=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT workspace_id FROM {$this->tables['folder_workspaces']} WHERE folder_id=%d",$folder_id)));
        $affected=array_values(array_unique(array_merge($old_ws,$valid_ws)));
        $wpdb->query('START TRANSACTION');
        if($wpdb->delete($this->tables['folder_workspaces'],['folder_id'=>$folder_id])===false || $wpdb->delete($this->tables['folder_notespaces'],['folder_id'=>$folder_id])===false){
            $wpdb->query('ROLLBACK'); wp_send_json_error(['message'=>'Could not update Folder'],500);
        }
        foreach($valid_ws as $i=>$wid){
            if($wpdb->insert($this->tables['folder_workspaces'],['folder_id'=>$folder_id,'workspace_id'=>$wid,'sort_order'=>(($i+1)*10),'created_at'=>current_time('mysql')])===false){
                $wpdb->query('ROLLBACK'); wp_send_json_error(['message'=>'Could not update Folder Workspaces'],500);
            }
        }
        foreach($valid_ns as $i=>$nid){
            if($wpdb->insert($this->tables['folder_notespaces'],['folder_id'=>$folder_id,'notespace_id'=>$nid,'sort_order'=>(($i+1)*10),'created_at'=>current_time('mysql')])===false){
                $wpdb->query('ROLLBACK'); wp_send_json_error(['message'=>'Could not update Folder Notespaces'],500);
            }
        }
        foreach($affected as $wid){
            if(!$this->sync_workspace_primary_folder($wid)){ $wpdb->query('ROLLBACK'); wp_send_json_error(['message'=>'Could not update Workspace Folder links'],500); }
        }
        $wpdb->query('COMMIT');
        wp_send_json_success(['workspace_ids'=>$valid_ws,'notespace_ids'=>$valid_ns]);
    }

    public function automatic_history_cleanup(){
        global $wpdb;
        $months=max(1,(int)get_option('bb_history_retention_months',12));
        $cutoff=(new DateTime(current_time('Y-m-d')))->modify('-'.$months.' months')->format('Y-m-d');
        $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['history']} WHERE due_date < %s",$cutoff));
        if($count>0){ $this->archive_history_before($cutoff); $wpdb->query($wpdb->prepare("DELETE FROM {$this->tables['history']} WHERE due_date < %s",$cutoff)); $this->purge_proof_attachments_before($cutoff); }
        $wpdb->query("DELETE FROM {$this->tables['notifications']} WHERE is_read=1 AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
        $wpdb->query("DELETE FROM {$this->tables['notifications']} WHERE created_at < DATE_SUB(NOW(), INTERVAL 365 DAY)");
    }

    public function ajax_purge_history(){
        $this->verify();
        if(!current_user_can('bb_manage_history') && !current_user_can('manage_options')) wp_send_json_error(['message'=>'History deletion permission required'],403);
        global $wpdb; $cutoff=sanitize_text_field($_POST['cutoff']??'');
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$cutoff)) wp_send_json_error(['message'=>'Valid cutoff date required']);
        $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['history']} WHERE due_date < %s",$cutoff));
        $this->archive_history_before($cutoff);
        $wpdb->query($wpdb->prepare("DELETE FROM {$this->tables['history']} WHERE due_date < %s",$cutoff));
        $proof_deleted=$this->purge_proof_attachments_before($cutoff);
        wp_send_json_success(['deleted'=>$count,'proofDeleted'=>$proof_deleted,'cutoff'=>$cutoff,'message'=>'Detailed history deleted; monthly score summaries were preserved.']);
    }


    /* ==========================================================
     * Network Messaging / Wallet / Razorpay (v4.13.0)
     * ========================================================== */

    private function secret_key(){ return hash('sha256',(defined('SECURE_AUTH_KEY')?SECURE_AUTH_KEY:'').wp_salt('auth'),true); }
    private function encrypt_secret($plain){
        $plain=(string)$plain; if($plain==='') return '';
        if(function_exists('openssl_encrypt')){
            $iv=random_bytes(12); $tag='';
            $cipher=openssl_encrypt($plain,'aes-256-gcm',$this->secret_key(),OPENSSL_RAW_DATA,$iv,$tag,'workboard');
            if($cipher!==false) return 'gcm:'.base64_encode($iv.$tag.$cipher);
        }
        if(function_exists('sodium_crypto_secretbox')){
            $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $key=substr($this->secret_key(),0,SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
            return 'sodium:'.base64_encode($nonce.sodium_crypto_secretbox($plain,$nonce,$key));
        }
        return '';
    }
    private function decrypt_secret($enc){
        $enc=(string)$enc; if($enc==='') return '';
        if(strpos($enc,'gcm:')===0 && function_exists('openssl_decrypt')){
            $raw=base64_decode(substr($enc,4),true); if($raw===false||strlen($raw)<29) return '';
            $iv=substr($raw,0,12); $tag=substr($raw,12,16); $cipher=substr($raw,28);
            $plain=openssl_decrypt($cipher,'aes-256-gcm',$this->secret_key(),OPENSSL_RAW_DATA,$iv,$tag,'workboard');
            return $plain===false?'':$plain;
        }
        if(strpos($enc,'sodium:')===0 && function_exists('sodium_crypto_secretbox_open')){
            $raw=base64_decode(substr($enc,7),true); if($raw===false||strlen($raw)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return '';
            $nonce=substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); $cipher=substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $key=substr($this->secret_key(),0,SODIUM_CRYPTO_SECRETBOX_KEYBYTES); $plain=sodium_crypto_secretbox_open($cipher,$nonce,$key);
            return $plain===false?'':$plain;
        }
        return '';
    }

    public function ensure_network_messaging_tables(){
        $ver=(string)get_site_option('bb_network_messaging_db_version','');
        if($ver==='1.2.0') return;
        global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php'; $charset=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$this->network_tables['site_settings']} (
            blog_id bigint(20) unsigned NOT NULL,
            messaging_mode varchar(20) NOT NULL DEFAULT 'disabled',
            active_mode varchar(20) NOT NULL DEFAULT 'wallet',
            retention_months int NOT NULL DEFAULT 12,
            utility_sell_override decimal(14,4) DEFAULT NULL,
            marketing_sell_override decimal(14,4) DEFAULT NULL,
            authentication_sell_override decimal(14,4) DEFAULT NULL,
            own_phone_number_id varchar(80) DEFAULT '',
            own_access_token_enc longtext,
            own_api_version varchar(20) DEFAULT 'v25.0',
            own_country_code varchar(12) DEFAULT '91',
            own_language varchar(20) DEFAULT 'en',
            own_connected tinyint(1) DEFAULT 0,
            last_test_status varchar(30) DEFAULT '',
            last_test_at datetime DEFAULT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (blog_id), KEY messaging_mode (messaging_mode)
        ) $charset;");
        dbDelta("CREATE TABLE {$this->network_tables['wallets']} (
            blog_id bigint(20) unsigned NOT NULL,
            balance decimal(14,4) NOT NULL DEFAULT 0,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (blog_id)
        ) $charset;");
        dbDelta("CREATE TABLE {$this->network_tables['wallet_ledger']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            blog_id bigint(20) unsigned NOT NULL,
            entry_type varchar(20) NOT NULL,
            amount decimal(14,4) NOT NULL DEFAULT 0,
            balance_after decimal(14,4) NOT NULL DEFAULT 0,
            reference_type varchar(40) DEFAULT '',
            reference_id bigint(20) unsigned DEFAULT 0,
            description varchar(255) DEFAULT '',
            created_by bigint(20) unsigned DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY blog_created (blog_id,created_at), KEY reference_lookup (reference_type,reference_id)
        ) $charset;");
        dbDelta("CREATE TABLE {$this->network_tables['recharges']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            blog_id bigint(20) unsigned NOT NULL,
            amount decimal(14,2) NOT NULL DEFAULT 0,
            currency varchar(10) NOT NULL DEFAULT 'INR',
            status varchar(20) NOT NULL DEFAULT 'pending',
            razorpay_order_id varchar(100) DEFAULT '',
            razorpay_payment_id varchar(100) DEFAULT '',
            signature_verified tinyint(1) DEFAULT 0,
            credited_at datetime DEFAULT NULL,
            created_by bigint(20) unsigned DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), UNIQUE KEY razorpay_order_id (razorpay_order_id), KEY blog_status (blog_id,status), KEY payment_id (razorpay_payment_id)
        ) $charset;");
        dbDelta("CREATE TABLE {$this->network_tables['message_log']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            blog_id bigint(20) unsigned NOT NULL,
            task_id bigint(20) unsigned DEFAULT 0,
            recipient_employee_id bigint(20) unsigned DEFAULT 0,
            recipient_name varchar(190) DEFAULT '',
            mobile varchar(40) DEFAULT '',
            channel varchar(20) NOT NULL DEFAULT 'whatsapp',
            message_type varchar(50) DEFAULT '',
            template_name varchar(190) DEFAULT '',
            template_category varchar(30) DEFAULT 'utility',
            title varchar(255) DEFAULT '',
            parameters_json longtext,
            auth_mode varchar(20) DEFAULT '',
            wallet_charge decimal(14,4) NOT NULL DEFAULT 0,
            pending_wallet_charge decimal(14,4) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'pending',
            http_code int DEFAULT 0,
            meta_message_id varchar(255) DEFAULT '',
            error_message text,
            trigger_type varchar(30) DEFAULT '',
            source_key varchar(190) DEFAULT '',
            sent_by bigint(20) unsigned DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY blog_created (blog_id,created_at), KEY blog_status (blog_id,status), KEY blog_source (blog_id,source_key), KEY meta_message_id (meta_message_id(100))
        ) $charset;");
        update_site_option('bb_network_messaging_db_version','1.2.0');
    }

    private function ensure_current_site_messaging_config(){
        global $wpdb; $blog_id=(int)get_current_blog_id();
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->network_tables['site_settings']} WHERE blog_id=%d",$blog_id));
        if(!$row){
            $legacy_token=trim((string)get_option('bb_meta_whatsapp_access_token',''));
            $legacy_phone=preg_replace('/\D+/','',(string)get_option('bb_meta_whatsapp_phone_number_id',''));
            $mode=($legacy_token&&$legacy_phone)?'own':'disabled';
            $wpdb->insert($this->network_tables['site_settings'],[
                'blog_id'=>$blog_id,'messaging_mode'=>$mode,'active_mode'=>$mode==='own'?'own':'wallet','retention_months'=>12,
                'own_phone_number_id'=>$legacy_phone,'own_access_token_enc'=>$legacy_token?$this->encrypt_secret($legacy_token):'',
                'own_api_version'=>(string)get_option('bb_meta_whatsapp_api_version','v25.0'),'own_country_code'=>(string)get_option('bb_meta_whatsapp_country_code','91'),
                'own_language'=>'en','own_connected'=>$legacy_token&&$legacy_phone?1:0,'updated_at'=>current_time('mysql')
            ]);
            if($legacy_token) delete_option('bb_meta_whatsapp_access_token');
        }elseif(empty($row->own_access_token_enc)){
            $legacy_token=trim((string)get_option('bb_meta_whatsapp_access_token',''));
            if($legacy_token){
                $wpdb->update($this->network_tables['site_settings'],['own_access_token_enc'=>$this->encrypt_secret($legacy_token),'own_connected'=>1,'updated_at'=>current_time('mysql')],['blog_id'=>$blog_id]);
                delete_option('bb_meta_whatsapp_access_token');
            }
        }
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$this->network_tables['wallets']} (blog_id,balance,updated_at) VALUES (%d,0,%s)",$blog_id,current_time('mysql')));
        update_option('bb_messaging_site_initialized',1,false);
    }

    private function site_messaging_row($blog_id=0){
        global $wpdb; $blog_id=$blog_id?:get_current_blog_id();
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->network_tables['site_settings']} WHERE blog_id=%d",$blog_id));
        if(!$row && (int)$blog_id===(int)get_current_blog_id()){ $this->ensure_current_site_messaging_config(); $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->network_tables['site_settings']} WHERE blog_id=%d",$blog_id)); }
        return $row;
    }
    private function site_own_token_configured($blog_id=0){ $r=$this->site_messaging_row($blog_id?:get_current_blog_id()); return $r && !empty($r->own_access_token_enc); }
    private function network_secret($option){ return $this->decrypt_secret((string)get_site_option($option,'')); }
    private function network_price($category,$kind='sell'){
        $category=in_array($category,['utility','marketing','authentication'],true)?$category:'utility';
        $raw=get_site_option('bb_msg_'.$category.'_'.$kind.'_price','');
        return $raw===''?null:(float)$raw;
    }
    private function site_selling_price($blog_id,$category){
        $r=$this->site_messaging_row($blog_id); $field=$category.'_sell_override';
        if($r && isset($r->$field) && $r->$field!==null && $r->$field!=='') return (float)$r->$field;
        return $this->network_price($category,'sell');
    }

    private function resolved_messaging_credentials(){
        $r=$this->site_messaging_row(get_current_blog_id());
        if(!$r) return ['mode'=>'disabled','error'=>'Network messaging policy is not configured for this site.'];
        $policy=(string)$r->messaging_mode;
        if($policy==='disabled') return ['mode'=>'disabled','error'=>'Messaging is disabled by the Network Admin.'];
        $mode=$policy==='either'?(in_array($r->active_mode,['wallet','own'],true)?$r->active_mode:'wallet'):$policy;
        if($mode==='wallet'){
            $token=$this->network_secret('bb_network_meta_access_token_enc');
            $phone=preg_replace('/\D+/','',(string)get_site_option('bb_network_meta_phone_number_id',''));
            if(!$token||!$phone) return ['mode'=>'wallet','error'=>'Network WhatsApp account is not configured.'];
            return ['mode'=>'wallet','token'=>$token,'phone_id'=>$phone,'api_version'=>(string)get_site_option('bb_network_meta_api_version','v25.0'),'country_code'=>(string)get_site_option('bb_network_meta_country_code','91'),'language'=>'en'];
        }
        if($mode==='own'){
            $token=$this->decrypt_secret((string)$r->own_access_token_enc); $phone=preg_replace('/\D+/','',(string)$r->own_phone_number_id);
            if(!$token||!$phone) return ['mode'=>'own','error'=>'Your own Meta WhatsApp account is not connected.'];
            return ['mode'=>'own','token'=>$token,'phone_id'=>$phone,'api_version'=>(string)$r->own_api_version,'country_code'=>(string)$r->own_country_code,'language'=>'en'];
        }
        return ['mode'=>'disabled','error'=>'Messaging is disabled.'];
    }

    private function wallet_balance($blog_id){ global $wpdb; $v=$wpdb->get_var($wpdb->prepare("SELECT balance FROM {$this->network_tables['wallets']} WHERE blog_id=%d",$blog_id)); return (float)($v?:0); }
    private function network_utc_now_mysql(){
        return gmdate('Y-m-d H:i:s');
    }

    private function network_utc_to_india_mysql($value){
        $value=trim((string)$value);
        if($value==='') return '';
        try{
            $utc=new DateTimeZone('UTC');
            $ist=new DateTimeZone('Asia/Kolkata');
            $dt=DateTimeImmutable::createFromFormat('Y-m-d H:i:s',$value,$utc);
            if(!$dt) return $value;
            return $dt->setTimezone($ist)->format('Y-m-d H:i:s');
        }catch(Throwable $e){
            return $value;
        }
    }

    private function wallet_credit($blog_id,$amount,$entry_type='credit',$reference_type='manual',$reference_id=0,$description='',$created_by=0){
        global $wpdb; $amount=round(abs((float)$amount),4); if($amount<=0) return false; $now=$this->network_utc_now_mysql();
        $wpdb->query('START TRANSACTION');
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$this->network_tables['wallets']} (blog_id,balance,updated_at) VALUES (%d,0,%s)",$blog_id,$now));
        $ok=$wpdb->query($wpdb->prepare("UPDATE {$this->network_tables['wallets']} SET balance=balance+%f,updated_at=%s WHERE blog_id=%d",$amount,$now,$blog_id));
        if($ok===false){ $wpdb->query('ROLLBACK'); return false; }
        $balance=$this->wallet_balance($blog_id);
        $ins=$wpdb->insert($this->network_tables['wallet_ledger'],['blog_id'=>$blog_id,'entry_type'=>sanitize_key($entry_type),'amount'=>$amount,'balance_after'=>$balance,'reference_type'=>sanitize_key($reference_type),'reference_id'=>absint($reference_id),'description'=>sanitize_text_field($description),'created_by'=>absint($created_by),'created_at'=>$now]);
        if($ins===false){ $wpdb->query('ROLLBACK'); return false; }
        $wpdb->query('COMMIT'); return true;
    }
    private function wallet_debit($blog_id,$amount,$reference_type='message',$reference_id=0,$description=''){
        global $wpdb; $amount=round(abs((float)$amount),4); if($amount<=0) return true; $now=$this->network_utc_now_mysql();
        $wpdb->query('START TRANSACTION');
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$this->network_tables['wallets']} (blog_id,balance,updated_at) VALUES (%d,0,%s)",$blog_id,$now));
        $ok=$wpdb->query($wpdb->prepare("UPDATE {$this->network_tables['wallets']} SET balance=balance-%f,updated_at=%s WHERE blog_id=%d AND balance>=%f",$amount,$now,$blog_id,$amount));
        if($ok!==1){ $wpdb->query('ROLLBACK'); return false; }
        $balance=$this->wallet_balance($blog_id);
        $ins=$wpdb->insert($this->network_tables['wallet_ledger'],['blog_id'=>$blog_id,'entry_type'=>'debit','amount'=>$amount,'balance_after'=>$balance,'reference_type'=>sanitize_key($reference_type),'reference_id'=>absint($reference_id),'description'=>sanitize_text_field($description),'created_by'=>get_current_user_id(),'created_at'=>$now]);
        if($ins===false){ $wpdb->query('ROLLBACK'); return false; }
        $wpdb->query('COMMIT'); return true;
    }

    private function message_log_create($data){
        global $wpdb; $emp_name=''; $eid=absint($data['recipient_employee_id']??0); $now=$this->network_utc_now_mysql();
        if($eid) $emp_name=(string)$wpdb->get_var($wpdb->prepare("SELECT name FROM {$this->tables['employees']} WHERE id=%d",$eid));
        $wpdb->insert($this->network_tables['message_log'],[
            'blog_id'=>get_current_blog_id(),'task_id'=>absint($data['task_id']??0),'recipient_employee_id'=>$eid,'recipient_name'=>sanitize_text_field($emp_name),'mobile'=>sanitize_text_field($data['mobile']??''),'channel'=>'whatsapp','message_type'=>sanitize_key($data['message_type']??''),'template_name'=>sanitize_key($data['template_name']??''),'template_category'=>sanitize_key($data['template_category']??'utility'),'title'=>sanitize_text_field($data['title']??''),'parameters_json'=>wp_json_encode(array_values((array)($data['parameters']??[]))),'auth_mode'=>sanitize_key($data['auth_mode']??''),'wallet_charge'=>0,'status'=>'pending','trigger_type'=>sanitize_key($data['trigger_type']??''),'source_key'=>sanitize_text_field($data['source_key']??''),'sent_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now
        ]);
        return (int)$wpdb->insert_id;
    }
    private function message_log_finish($id,$status,$meta_id='',$http_code=0,$error='',$charge=0){
        if(!$id) return; global $wpdb;
        $wpdb->update($this->network_tables['message_log'],['status'=>sanitize_key($status),'meta_message_id'=>sanitize_text_field($meta_id),'http_code'=>absint($http_code),'error_message'=>sanitize_text_field($error),'wallet_charge'=>round((float)$charge,4),'updated_at'=>$this->network_utc_now_mysql()],['id'=>absint($id)]);
    }
    private function site_message_log_rows($blog_id,$limit=25,$offset=0){
        global $wpdb; $limit=max(1,min(100,absint($limit))); $offset=max(0,absint($offset));
        $rows=$wpdb->get_results($wpdb->prepare("SELECT id,created_at,recipient_name,mobile,message_type,template_name,auth_mode,wallet_charge,status,meta_message_id,error_message,trigger_type FROM {$this->network_tables['message_log']} WHERE blog_id=%d ORDER BY id DESC LIMIT %d OFFSET %d",$blog_id,$limit,$offset));
        foreach((array)$rows as $row){ if(isset($row->created_at)) $row->created_at=$this->network_utc_to_india_mysql($row->created_at); }
        return $rows;
    }

    private function site_wallet_ledger_rows($blog_id,$limit=10){
        global $wpdb; $limit=max(1,min(100,absint($limit)));
        $rows=$wpdb->get_results($wpdb->prepare("SELECT id,entry_type,amount,balance_after,reference_type,description,created_at FROM {$this->network_tables['wallet_ledger']} WHERE blog_id=%d ORDER BY id DESC LIMIT %d",$blog_id,$limit));
        foreach((array)$rows as $row){ if(isset($row->created_at)) $row->created_at=$this->network_utc_to_india_mysql($row->created_at); }
        return $rows;
    }

    private function current_site_messaging_state(){
        $r=$this->site_messaging_row(get_current_blog_id()); if(!$r) return [];
        $policy=(string)$r->messaging_mode; $active=$policy==='either'?(string)$r->active_mode:$policy;
        return [
            'networkMode'=>$policy,'activeMode'=>$active,'walletAllowed'=>in_array($policy,['wallet','either'],true)?1:0,'ownAllowed'=>in_array($policy,['own','either'],true)?1:0,'whatsappEnabled'=>(int)get_option('bb_meta_whatsapp_enabled',0),
            'walletBalance'=>$this->wallet_balance(get_current_blog_id()),'lowBalanceThreshold'=>(float)get_site_option('bb_wallet_low_balance_threshold',100),
            'utilitySellPrice'=>$this->site_selling_price(get_current_blog_id(),'utility'),'marketingSellPrice'=>$this->site_selling_price(get_current_blog_id(),'marketing'),'authenticationSellPrice'=>$this->site_selling_price(get_current_blog_id(),'authentication'),
            'retentionMonths'=>(int)$r->retention_months,'ownPhoneNumberId'=>(string)$r->own_phone_number_id,'ownTokenConfigured'=>!empty($r->own_access_token_enc)?1:0,
            'ownApiVersion'=>(string)$r->own_api_version,'ownCountryCode'=>(string)$r->own_country_code,'ownLanguage'=>'en',
            'razorpayConfigured'=>(get_site_option('bb_razorpay_key_id','') && get_site_option('bb_razorpay_key_secret_enc',''))?1:0,'minRecharge'=>(float)get_site_option('bb_wallet_min_recharge',500),
            'canEditOwnToken'=>(is_multisite() && is_super_admin() && get_current_blog_id()!==get_main_site_id())?0:1,
        ];
    }

    public function ajax_save_site_messaging_settings(){
        $this->verify(); $this->require_manage(); global $wpdb; $r=$this->site_messaging_row(get_current_blog_id());
        if(!$r) wp_send_json_error(['message'=>'Messaging policy not configured'],404);
        $policy=(string)$r->messaging_mode; $active=sanitize_key($_POST['active_mode']??$r->active_mode);
        if($policy==='wallet') $active='wallet'; elseif($policy==='own') $active='own'; elseif($policy==='disabled') $active='disabled'; elseif(!in_array($active,['wallet','own'],true)) $active='wallet';
        $data=['active_mode'=>$active,'updated_at'=>current_time('mysql')];
        update_option('bb_meta_whatsapp_enabled',!empty($_POST['whatsapp_enabled'])?1:0);
        if(in_array($policy,['own','either'],true)){
            $data['own_phone_number_id']=preg_replace('/\D+/','',(string)($_POST['own_phone_number_id']??$r->own_phone_number_id));
            $version=sanitize_text_field($_POST['own_api_version']??$r->own_api_version); if(!preg_match('/^v\d+\.\d+$/',$version)) $version='v25.0'; $data['own_api_version']=$version;
            $data['own_country_code']=preg_replace('/\D+/','',(string)($_POST['own_country_code']??$r->own_country_code)) ?: '91';
            $data['own_language']='en';
            $token=trim((string)wp_unslash($_POST['own_access_token']??''));
            if($token!==''){
                if(is_multisite() && is_super_admin() && get_current_blog_id()!==get_main_site_id()) wp_send_json_error(['message'=>'For privacy, Network Admins cannot enter or retrieve a client site token. The site owner must connect it.'],403);
                $data['own_access_token_enc']=$this->encrypt_secret($token); $data['own_connected']=1;
            }
        }
        $wpdb->update($this->network_tables['site_settings'],$data,['blog_id'=>get_current_blog_id()]);
        wp_send_json_success(['messagingSettings'=>$this->current_site_messaging_state()]);
    }

    public function ajax_get_message_log(){
        $this->verify(); $this->require_manage(); $page=max(1,absint($_POST['page']??1)); $per=25; global $wpdb; $blog=get_current_blog_id();
        $total=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->network_tables['message_log']} WHERE blog_id=%d",$blog));
        wp_send_json_success(['rows'=>$this->site_message_log_rows($blog,$per,($page-1)*$per),'page'=>$page,'pages'=>max(1,(int)ceil($total/$per)),'total'=>$total]);
    }

    public function ajax_wallet_create_recharge(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $r=$this->site_messaging_row(get_current_blog_id()); if(!$r || !in_array($r->messaging_mode,['wallet','either'],true)) wp_send_json_error(['message'=>'Wallet recharge is not enabled for this site.'],403);
        $amount=round((float)($_POST['amount']??0),2); $min=max(1,(float)get_site_option('bb_wallet_min_recharge',500)); if($amount<$min) wp_send_json_error(['message'=>'Minimum recharge is ₹'.number_format($min,2)]);
        $key=trim((string)get_site_option('bb_razorpay_key_id','')); $secret=$this->network_secret('bb_razorpay_key_secret_enc'); if(!$key||!$secret) wp_send_json_error(['message'=>'Razorpay is not configured by the Network Admin.']);
        $wpdb->insert($this->network_tables['recharges'],['blog_id'=>get_current_blog_id(),'amount'=>$amount,'currency'=>'INR','status'=>'creating','created_by'=>get_current_user_id(),'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);
        $rid=(int)$wpdb->insert_id; if(!$rid) wp_send_json_error(['message'=>'Could not create recharge request.']);
        $receipt='WB-'.get_current_blog_id().'-'.$rid;
        $res=wp_remote_post('https://api.razorpay.com/v1/orders',['timeout'=>20,'headers'=>['Authorization'=>'Basic '.base64_encode($key.':'.$secret),'Content-Type'=>'application/json'],'body'=>wp_json_encode(['amount'=>(int)round($amount*100),'currency'=>'INR','receipt'=>$receipt,'notes'=>['site_id'=>(string)get_current_blog_id(),'recharge_id'=>(string)$rid]])]);
        if(is_wp_error($res)){ $wpdb->update($this->network_tables['recharges'],['status'=>'failed','updated_at'=>current_time('mysql')],['id'=>$rid]); wp_send_json_error(['message'=>$res->get_error_message()]); }
        $code=(int)wp_remote_retrieve_response_code($res); $body=json_decode((string)wp_remote_retrieve_body($res),true); $order=(string)($body['id']??'');
        if($code<200||$code>=300||!$order){ $wpdb->update($this->network_tables['recharges'],['status'=>'failed','updated_at'=>current_time('mysql')],['id'=>$rid]); wp_send_json_error(['message'=>sanitize_text_field($body['error']['description']??'Could not create Razorpay order.')]); }
        $wpdb->update($this->network_tables['recharges'],['status'=>'pending','razorpay_order_id'=>$order,'updated_at'=>current_time('mysql')],['id'=>$rid]);
        $u=wp_get_current_user();
        wp_send_json_success(['rechargeId'=>$rid,'key'=>$key,'orderId'=>$order,'amount'=>$amount,'amountPaise'=>(int)round($amount*100),'currency'=>'INR','name'=>(string)get_option('bb_org_name',get_bloginfo('name')),'prefillName'=>$u->display_name,'prefillEmail'=>$u->user_email]);
    }

    private function credit_recharge_if_needed($recharge_id,$payment_id){
        global $wpdb; $wpdb->query('START TRANSACTION');
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->network_tables['recharges']} WHERE id=%d FOR UPDATE",$recharge_id));
        if(!$row){ $wpdb->query('ROLLBACK'); return false; }
        if($row->status==='paid' && !empty($row->credited_at)){ $wpdb->query('COMMIT'); return true; }
        $blog_id=(int)$row->blog_id; $amount=round(abs((float)$row->amount),4); if($amount<=0){ $wpdb->query('ROLLBACK'); return false; }
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$this->network_tables['wallets']} (blog_id,balance,updated_at) VALUES (%d,0,%s)",$blog_id,current_time('mysql')));
        if($wpdb->query($wpdb->prepare("UPDATE {$this->network_tables['wallets']} SET balance=balance+%f,updated_at=%s WHERE blog_id=%d",$amount,current_time('mysql'),$blog_id))===false){ $wpdb->query('ROLLBACK'); return false; }
        $balance=(float)$wpdb->get_var($wpdb->prepare("SELECT balance FROM {$this->network_tables['wallets']} WHERE blog_id=%d",$blog_id));
        if($wpdb->insert($this->network_tables['wallet_ledger'],['blog_id'=>$blog_id,'entry_type'=>'credit','amount'=>$amount,'balance_after'=>$balance,'reference_type'=>'recharge','reference_id'=>absint($recharge_id),'description'=>'Razorpay wallet recharge','created_by'=>(int)$row->created_by,'created_at'=>current_time('mysql')])===false){ $wpdb->query('ROLLBACK'); return false; }
        if($wpdb->update($this->network_tables['recharges'],['status'=>'paid','razorpay_payment_id'=>sanitize_text_field($payment_id),'signature_verified'=>1,'credited_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')],['id'=>$recharge_id])===false){ $wpdb->query('ROLLBACK'); return false; }
        $wpdb->query('COMMIT'); return true;
    }

    public function ajax_wallet_verify_recharge(){
        $this->verify(); $this->require_manage(); global $wpdb;
        $order=sanitize_text_field($_POST['razorpay_order_id']??''); $payment=sanitize_text_field($_POST['razorpay_payment_id']??''); $signature=sanitize_text_field($_POST['razorpay_signature']??'');
        $secret=$this->network_secret('bb_razorpay_key_secret_enc'); if(!$order||!$payment||!$signature||!$secret) wp_send_json_error(['message'=>'Incomplete Razorpay verification data.']);
        $expected=hash_hmac('sha256',$order.'|'.$payment,$secret); if(!hash_equals($expected,$signature)) wp_send_json_error(['message'=>'Razorpay signature verification failed.'],403);
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->network_tables['recharges']} WHERE razorpay_order_id=%s AND blog_id=%d",$order,get_current_blog_id())); if(!$row) wp_send_json_error(['message'=>'Recharge order not found.'],404);
        if(!$this->credit_recharge_if_needed((int)$row->id,$payment)) wp_send_json_error(['message'=>'Payment verified but wallet credit failed. Please contact the Network Admin.']);
        wp_send_json_success(['message'=>'Wallet recharged successfully.','balance'=>$this->wallet_balance(get_current_blog_id())]);
    }

    private function wallet_has_message_entry($log_id,$entry_type){
        global $wpdb;
        return (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->network_tables['wallet_ledger']} WHERE reference_type='message' AND reference_id=%d AND entry_type=%s LIMIT 1",absint($log_id),sanitize_key($entry_type)));
    }

    private function whatsapp_status_rank($status){
        $map=['pending'=>0,'accepted'=>1,'sent'=>2,'delivered'=>3,'read'=>4,'failed'=>99];
        return $map[$status]??0;
    }

    private function update_attendance_test_from_message_log($row,$status,$error=''){
        if(!$row || strpos((string)$row->source_key,'attendance-test-')!==0) return;
        $blog_id=(int)$row->blog_id; if(!$blog_id) return;
        $switched=false;
        if(get_current_blog_id()!==$blog_id){ switch_to_blog($blog_id); $switched=true; }
        update_option('bb_attendance_whatsapp_last_test_status',sanitize_key($status));
        update_option('bb_attendance_whatsapp_last_test_message_id',(string)$row->meta_message_id);
        update_option('bb_attendance_whatsapp_last_test_error',sanitize_text_field($error));
        if($switched) restore_current_blog();
    }

    private function apply_whatsapp_delivery_status($meta_id,$status,$error=''){
        global $wpdb;
        $meta_id=sanitize_text_field((string)$meta_id); $status=sanitize_key((string)$status);
        if(!$meta_id || !in_array($status,['sent','delivered','read','failed'],true)) return false;
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->network_tables['message_log']} WHERE meta_message_id=%s ORDER BY id DESC LIMIT 1",$meta_id));
        if(!$row) return false;
        $current=sanitize_key((string)$row->status);
        if($current==='failed' && $status!=='failed') return true;
        if($status!=='failed' && $this->whatsapp_status_rank($status)<$this->whatsapp_status_rank($current)) return true;
        $charge=(float)($row->pending_wallet_charge??0);
        if(in_array($status,['sent','delivered','read'],true) && $charge>0 && !$this->wallet_has_message_entry((int)$row->id,'debit')){
            if(!$this->wallet_debit((int)$row->blog_id,$charge,'message',(int)$row->id,'WhatsApp Utility message')){
                $error='Delivered by Meta, but Workboard wallet debit could not be posted.';
            }
        }
        if($status==='failed' && $this->wallet_has_message_entry((int)$row->id,'debit') && !$this->wallet_has_message_entry((int)$row->id,'refund')){
            $this->wallet_credit((int)$row->blog_id,$charge,'refund','message',(int)$row->id,'Refund for failed WhatsApp message',0);
        }
        $actual_charge=$this->wallet_has_message_entry((int)$row->id,'debit') && !$this->wallet_has_message_entry((int)$row->id,'refund') ? $charge : 0;
        $wpdb->update($this->network_tables['message_log'],[
            'status'=>$status,'error_message'=>sanitize_text_field($error),'wallet_charge'=>round($actual_charge,4),'updated_at'=>$this->network_utc_now_mysql()
        ],['id'=>(int)$row->id]);
        $row->status=$status; $row->error_message=$error; $row->wallet_charge=$actual_charge;
        $this->update_attendance_test_from_message_log($row,$status,$error);
        return true;
    }

    public function register_whatsapp_rest_route(){
        register_rest_route('business-board/v1','/whatsapp-webhook',[
            ['methods'=>'GET','callback'=>[$this,'whatsapp_webhook'],'permission_callback'=>'__return_true'],
            ['methods'=>'POST','callback'=>[$this,'whatsapp_webhook'],'permission_callback'=>'__return_true'],
        ]);
    }

    public function whatsapp_webhook($request){
        $verify=(string)get_site_option('bb_network_meta_webhook_verify_token','');
        if(!$verify){ $verify=wp_generate_password(32,false,false); update_site_option('bb_network_meta_webhook_verify_token',$verify); }
        if(strtoupper($request->get_method())==='GET'){
            $mode=(string)$request->get_param('hub_mode'); if($mode==='') $mode=(string)$request->get_param('hub.mode');
            $token=(string)$request->get_param('hub_verify_token'); if($token==='') $token=(string)$request->get_param('hub.verify_token');
            $challenge=(string)$request->get_param('hub_challenge'); if($challenge==='') $challenge=(string)$request->get_param('hub.challenge');
            if($mode==='subscribe' && $token!=='' && hash_equals($verify,$token)){
                header('Content-Type: text/plain; charset=UTF-8'); echo esc_html($challenge); exit;
            }
            return new WP_REST_Response(['ok'=>false,'message'=>'Webhook verification failed'],403);
        }
        $raw=(string)$request->get_body();
        $app_secret=$this->network_secret('bb_network_meta_app_secret_enc');
        if($app_secret){
            $sig=(string)$request->get_header('x-hub-signature-256');
            $expected='sha256='.hash_hmac('sha256',$raw,$app_secret);
            if(!$sig || !hash_equals($expected,$sig)) return new WP_REST_Response(['ok'=>false,'message'=>'Invalid Meta webhook signature'],401);
        }
        $payload=json_decode($raw,true); if(!is_array($payload)) return new WP_REST_Response(['ok'=>false],400);
        $processed=0;
        foreach((array)($payload['entry']??[]) as $entry){
            foreach((array)($entry['changes']??[]) as $change){
                foreach((array)($change['value']['statuses']??[]) as $st){
                    $id=(string)($st['id']??''); $status=sanitize_key((string)($st['status']??''));
                    $error='';
                    if($status==='failed'){
                        $errs=(array)($st['errors']??[]); $first=$errs[0]??[];
                        $error=(string)($first['message']??$first['title']??'WhatsApp delivery failed');
                        $details=(string)($first['error_data']['details']??''); if($details!=='') $error.=' — '.$details;
                    }
                    if($id && $this->apply_whatsapp_delivery_status($id,$status,$error)) $processed++;
                }
            }
        }
        return new WP_REST_Response(['ok'=>true,'processed'=>$processed],200);
    }

    public function register_razorpay_rest_route(){
        register_rest_route('business-board/v1','/razorpay-webhook',['methods'=>'POST','callback'=>[$this,'razorpay_webhook'],'permission_callback'=>'__return_true']);
    }
    public function razorpay_webhook($request){
        $secret=$this->network_secret('bb_razorpay_webhook_secret_enc'); if(!$secret) return new WP_REST_Response(['ok'=>false,'message'=>'Webhook secret not configured'],503);
        $raw=$request->get_body(); $sig=(string)$request->get_header('x-razorpay-signature'); $expected=hash_hmac('sha256',$raw,$secret); if(!$sig||!hash_equals($expected,$sig)) return new WP_REST_Response(['ok'=>false,'message'=>'Invalid signature'],401);
        $payload=json_decode($raw,true); $event=(string)($payload['event']??'');
        if(!in_array($event,['payment.captured','order.paid'],true)) return new WP_REST_Response(['ok'=>true,'ignored'=>true],200);
        $payment=$payload['payload']['payment']['entity']??[]; $order_id=(string)($payment['order_id']??($payload['payload']['order']['entity']['id']??'')); $payment_id=(string)($payment['id']??'');
        if(!$order_id) return new WP_REST_Response(['ok'=>true,'ignored'=>true],200);
        global $wpdb; $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->network_tables['recharges']} WHERE razorpay_order_id=%s",$order_id)); if(!$row) return new WP_REST_Response(['ok'=>true,'ignored'=>true],200);
        $ok=$this->credit_recharge_if_needed((int)$row->id,$payment_id); return new WP_REST_Response(['ok'=>$ok],$ok?200:500);
    }

    public function ensure_network_message_cleanup_cron(){
        if(is_multisite() && get_current_blog_id()!==get_main_site_id()) return;
        if(!wp_next_scheduled('bb_network_message_cleanup')) wp_schedule_event(time()+3600,'daily','bb_network_message_cleanup');
    }
    public function network_message_cleanup(){
        if(is_multisite() && get_current_blog_id()!==get_main_site_id()) return; global $wpdb;
        $rows=$wpdb->get_results("SELECT blog_id,retention_months FROM {$this->network_tables['site_settings']} WHERE retention_months>0");
        foreach($rows as $r){ $months=max(1,(int)$r->retention_months); $wpdb->query($wpdb->prepare("DELETE FROM {$this->network_tables['message_log']} WHERE blog_id=%d AND created_at < DATE_SUB(NOW(), INTERVAL %d MONTH)",(int)$r->blog_id,$months)); }
    }

    public function network_admin_menu(){
        if(!is_multisite()) return;
        add_menu_page('Workboard Messaging','Workboard Messaging','manage_network_options','workboard-messaging',[$this,'render_network_messaging_admin'],'dashicons-whatsapp',31);
        add_submenu_page('workboard-messaging','Site Storage','Site Storage','manage_network_options','workboard-site-storage',[$this,'render_network_storage_admin']);
    }
    public function render_network_storage_admin(){
        if(!current_user_can('manage_network_options')) wp_die('Network Admin access required.');
        $notice='';
        if(($_SERVER['REQUEST_METHOD']??'')==='POST' && !empty($_POST['bb_storage_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bb_storage_nonce'])),'bb_network_storage_save')){
            $blog_id=absint($_POST['blog_id']??0); $limit=max(1,absint($_POST['storage_limit_mb']??1024));
            if($blog_id && get_blog_details($blog_id)){
                switch_to_blog($blog_id);
                update_option('bb_storage_limit_mb',$limit,false);
                // Keep WordPress Multisite's native media quota aligned with Workboard.
                if(is_multisite()) update_option('blog_upload_space',$limit,false);
                $this->clear_site_storage_cache();
                restore_current_blog();
                $notice='Site storage limit updated.';
            }
        }
        $sites=get_sites(['number'=>0,'deleted'=>0,'archived'=>0]); $rows=[];
        foreach($sites as $site){
            $blog_id=(int)$site->blog_id; $details=get_blog_details($blog_id);
            switch_to_blog($blog_id);
            if(get_option('bb_storage_limit_mb')===false) update_option('bb_storage_limit_mb',1024,false);
            $st=$this->current_site_storage_state(false);
            restore_current_blog();
            $rows[]=['id'=>$blog_id,'name'=>$details?$details->blogname:('Site '.$blog_id),'url'=>$details?$details->siteurl:'','s'=>$st];
        }
        ?>
        <div class="wrap"><h1>Workboard Site Storage</h1><?php if($notice):?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice);?></p></div><?php endif;?>
        <style>.bbs-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:20px;margin:18px 0}.bbs-table{width:100%;border-collapse:collapse}.bbs-table th,.bbs-table td{padding:11px 10px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:middle}.bbs-table th{background:#f6f7f7}.bbs-meter{width:180px;max-width:100%;height:8px;background:#e5e7eb;border-radius:999px;overflow:hidden;margin-top:5px}.bbs-meter i{display:block;height:100%;background:#2271b1}.bbs-limit-form{display:flex;gap:7px;align-items:center}.bbs-limit-form input{width:110px}.bbs-small{color:#646970;font-size:12px}@media(max-width:782px){.bbs-table{display:block;overflow:auto}.bbs-limit-form{min-width:230px}}</style>
        <div class="bbs-card"><h2>Site Space Limits</h2><p>Every site receives <strong>1024 MB</strong> by default. Space limits can only be changed here by the Network Admin.</p>
        <table class="bbs-table"><thead><tr><th>Site</th><th>Total Space</th><th>Utilized</th><th>Balance</th><th>Usage</th><th>Update Space</th></tr></thead><tbody>
        <?php foreach($rows as $r): $st=$r['s'];?><tr>
          <td><strong><?php echo esc_html($r['name']);?></strong><br><span class="bbs-small">Site #<?php echo (int)$r['id'];?><?php if($r['url']):?> · <?php echo esc_html($r['url']);?><?php endif;?></span></td>
          <td><?php echo esc_html($st['totalLabel']);?></td><td><?php echo esc_html($st['usedLabel']);?></td><td><?php echo esc_html($st['balanceLabel']);?></td>
          <td><strong><?php echo esc_html($st['percent']);?>%</strong><div class="bbs-meter"><i style="width:<?php echo esc_attr($st['percent']);?>%"></i></div></td>
          <td><form method="post" class="bbs-limit-form"><?php wp_nonce_field('bb_network_storage_save','bb_storage_nonce');?><input type="hidden" name="blog_id" value="<?php echo (int)$r['id'];?>"><input type="number" min="1" step="1" name="storage_limit_mb" value="<?php echo (int)$st['limitMb'];?>"><span>MB</span><button class="button button-primary">Update</button></form></td>
        </tr><?php endforeach;?>
        </tbody></table></div></div><?php
    }

    public function render_network_messaging_admin(){
        if(!current_user_can('manage_network_options')) wp_die('Network Admin access required.'); $this->ensure_network_messaging_tables(); global $wpdb; $notice='';
        if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['bb_network_messaging_nonce'])){
            check_admin_referer('bb_network_messaging_save','bb_network_messaging_nonce'); $action=sanitize_key($_POST['bb_network_action']??'');
            if($action==='settings'){
                foreach(['utility','marketing','authentication'] as $cat){ foreach(['cost','sell'] as $kind){ $v=trim((string)($_POST[$cat.'_'.$kind]??'')); update_site_option('bb_msg_'.$cat.'_'.$kind.'_price',$v===''?'':max(0,(float)$v)); } }
                update_site_option('bb_wallet_min_recharge',max(1,(float)($_POST['min_recharge']??500))); update_site_option('bb_wallet_low_balance_threshold',max(0,(float)($_POST['low_balance']??100)));
                update_site_option('bb_razorpay_key_id',sanitize_text_field($_POST['razorpay_key_id']??''));
                $sec=trim((string)wp_unslash($_POST['razorpay_key_secret']??'')); if($sec!=='') update_site_option('bb_razorpay_key_secret_enc',$this->encrypt_secret($sec));
                $wh=trim((string)wp_unslash($_POST['razorpay_webhook_secret']??'')); if($wh!=='') update_site_option('bb_razorpay_webhook_secret_enc',$this->encrypt_secret($wh));
                update_site_option('bb_network_meta_phone_number_id',preg_replace('/\D+/','',(string)($_POST['network_meta_phone_id']??'')));
                $mt=trim((string)wp_unslash($_POST['network_meta_token']??'')); if($mt!=='') update_site_option('bb_network_meta_access_token_enc',$this->encrypt_secret($mt));
                $mv=sanitize_text_field($_POST['network_meta_api_version']??'v25.0'); if(!preg_match('/^v\d+\.\d+$/',$mv))$mv='v25.0'; update_site_option('bb_network_meta_api_version',$mv);
                update_site_option('bb_network_meta_country_code',preg_replace('/\D+/','',(string)($_POST['network_meta_country_code']??'91'))?:'91'); update_site_option('bb_network_meta_language','en');
                $appsec=trim((string)wp_unslash($_POST['network_meta_app_secret']??'')); if($appsec!=='') update_site_option('bb_network_meta_app_secret_enc',$this->encrypt_secret($appsec));
                if(!get_site_option('bb_network_meta_webhook_verify_token','')) update_site_option('bb_network_meta_webhook_verify_token',wp_generate_password(32,false,false));
                $notice='Network messaging, pricing and payment settings saved.';
            }elseif($action==='site_policy'){
                $blog=absint($_POST['blog_id']??0); $mode=sanitize_key($_POST['messaging_mode']??'disabled'); if(!in_array($mode,['disabled','wallet','own','either'],true))$mode='disabled';
                $ret=max(0,min(120,absint($_POST['retention_months']??12))); $data=['messaging_mode'=>$mode,'retention_months'=>$ret,'updated_at'=>current_time('mysql')];
                foreach(['utility','marketing','authentication'] as $cat){ $v=trim((string)($_POST[$cat.'_override']??'')); $data[$cat.'_sell_override']=$v===''?null:max(0,(float)$v); }
                $wpdb->update($this->network_tables['site_settings'],$data,['blog_id'=>$blog]); $notice='Site messaging policy updated.';
            }elseif($action==='manual_credit'){
                $blog=absint($_POST['blog_id']??0); $amount=round((float)($_POST['credit_amount']??0),2); $note=sanitize_text_field($_POST['credit_note']??'Manual credit');
                if($blog&&$amount>0&&$this->wallet_credit($blog,$amount,'credit','manual',0,$note,get_current_user_id())) $notice='Manual wallet credit added.'; else $notice='Could not add wallet credit.';
            }
        }
        $sites=get_sites(['number'=>0,'deleted'=>0,'archived'=>0]); foreach($sites as $site){ switch_to_blog((int)$site->blog_id); $this->ensure_current_site_messaging_config(); restore_current_blog(); }
        $rows=$wpdb->get_results("SELECT s.*,COALESCE(w.balance,0) wallet_balance,(SELECT COUNT(*) FROM {$this->network_tables['message_log']} l WHERE l.blog_id=s.blog_id AND l.created_at>=DATE_FORMAT(NOW(),'%Y-%m-01')) month_messages FROM {$this->network_tables['site_settings']} s LEFT JOIN {$this->network_tables['wallets']} w ON w.blog_id=s.blog_id ORDER BY s.blog_id");
        $site_map=[]; foreach($sites as $site) $site_map[(int)$site->blog_id]=get_blog_details((int)$site->blog_id);
        $g=function($k,$d=''){return get_site_option($k,$d);}; if(!$g('bb_network_meta_webhook_verify_token','')) update_site_option('bb_network_meta_webhook_verify_token',wp_generate_password(32,false,false)); $rp_configured=$g('bb_razorpay_key_id','')&&$g('bb_razorpay_key_secret_enc',''); $meta_configured=$g('bb_network_meta_phone_number_id','')&&$g('bb_network_meta_access_token_enc','');
        ?>
        <div class="wrap"><h1>Workboard Messaging</h1><?php if($notice):?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice);?></p></div><?php endif;?>
        <style>.bbn-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:20px;margin:18px 0}.bbn-grid{display:grid;grid-template-columns:repeat(3,minmax(190px,1fr));gap:14px}.bbn-grid label{font-weight:600}.bbn-grid input,.bbn-grid select{width:100%;margin-top:6px}.bbn-table{width:100%;border-collapse:collapse}.bbn-table th,.bbn-table td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}.bbn-site-form{display:grid;grid-template-columns:150px 100px 110px 110px 110px auto;gap:6px;align-items:center}.bbn-site-form input,.bbn-site-form select{width:100%}.bbn-credit{display:flex;gap:6px;margin-top:8px}.bbn-status{font-size:12px;font-weight:700}.bbn-ok{color:#15803d}.bbn-off{color:#64748b}@media(max-width:1100px){.bbn-grid{grid-template-columns:1fr 1fr}.bbn-site-form{grid-template-columns:1fr 1fr}}@media(max-width:700px){.bbn-grid{grid-template-columns:1fr}.bbn-site-form{grid-template-columns:1fr}}</style>
        <div class="bbn-card"><h2>Network Account & Pricing</h2><p>Wallet sites use the Network Meta account. Site owners using their own Meta account keep their token private; Network Admin sees only connection status.</p><form method="post"><?php wp_nonce_field('bb_network_messaging_save','bb_network_messaging_nonce');?><input type="hidden" name="bb_network_action" value="settings"><h3>Selling & Cost Price per message</h3><div class="bbn-grid">
        <?php foreach(['utility'=>'Utility','marketing'=>'Marketing','authentication'=>'Authentication'] as $cat=>$label):?><label><?php echo esc_html($label);?> Cost Price (₹)<input type="number" step="0.0001" min="0" name="<?php echo esc_attr($cat);?>_cost" value="<?php echo esc_attr($g('bb_msg_'.$cat.'_cost_price',''));?>"></label><label><?php echo esc_html($label);?> Selling Price (₹)<input type="number" step="0.0001" min="0" name="<?php echo esc_attr($cat);?>_sell" value="<?php echo esc_attr($g('bb_msg_'.$cat.'_sell_price',''));?>"></label><?php endforeach;?>
        <label>Minimum Recharge (₹)<input type="number" step="1" min="1" name="min_recharge" value="<?php echo esc_attr($g('bb_wallet_min_recharge',500));?>"></label><label>Low Balance Warning (₹)<input type="number" step="1" min="0" name="low_balance" value="<?php echo esc_attr($g('bb_wallet_low_balance_threshold',100));?>"></label></div>
        <h3>Network Meta WhatsApp Account <span class="bbn-status <?php echo $meta_configured?'bbn-ok':'bbn-off';?>"><?php echo $meta_configured?'Connected':'Not configured';?></span></h3><div class="bbn-grid"><label>Phone Number ID<input type="text" name="network_meta_phone_id" value="<?php echo esc_attr($g('bb_network_meta_phone_number_id',''));?>"></label><label>Permanent Access Token<input type="password" name="network_meta_token" value="" placeholder="<?php echo $g('bb_network_meta_access_token_enc','')?'Configured — leave blank to keep':'Enter token';?>"></label><label>Graph API Version<input type="text" name="network_meta_api_version" value="<?php echo esc_attr($g('bb_network_meta_api_version','v25.0'));?>"></label><label>Country Code<input type="text" name="network_meta_country_code" value="<?php echo esc_attr($g('bb_network_meta_country_code','91'));?>"></label><label>Template Language<input type="text" value="en" readonly><small>Fixed to English (en).</small></label><label>Meta App Secret<input type="password" name="network_meta_app_secret" value="" placeholder="<?php echo $g('bb_network_meta_app_secret_enc','')?'Configured — leave blank to keep':'Recommended for webhook security';?>"></label><label style="grid-column:1/-1">WhatsApp Webhook URL<input type="text" readonly value="<?php echo esc_attr(network_home_url('/wp-json/business-board/v1/whatsapp-webhook'));?>"></label><label style="grid-column:1/-1">WhatsApp Verify Token<input type="text" readonly value="<?php echo esc_attr($g('bb_network_meta_webhook_verify_token',''));?>"><small>Enter this Verify Token in Meta when configuring the WhatsApp webhook. Subscribe to message status updates.</small></label></div>
        <h3>Razorpay <span class="bbn-status <?php echo $rp_configured?'bbn-ok':'bbn-off';?>"><?php echo $rp_configured?'Configured':'Not configured';?></span></h3><div class="bbn-grid"><label>Key ID<input type="text" name="razorpay_key_id" value="<?php echo esc_attr($g('bb_razorpay_key_id',''));?>"></label><label>Key Secret<input type="password" name="razorpay_key_secret" value="" placeholder="<?php echo $g('bb_razorpay_key_secret_enc','')?'Configured — leave blank to keep':'Enter Key Secret';?>"></label><label>Webhook Secret<input type="password" name="razorpay_webhook_secret" value="" placeholder="<?php echo $g('bb_razorpay_webhook_secret_enc','')?'Configured — leave blank to keep':'Enter Webhook Secret';?>"></label><label style="grid-column:1/-1">Webhook URL<input type="text" readonly value="<?php echo esc_attr(network_home_url('/wp-json/business-board/v1/razorpay-webhook'));?>"></label></div><p><button class="button button-primary">Save Network Settings</button></p></form></div>
        <div class="bbn-card"><h2>Site Messaging Policy & Wallet</h2><p>Message logs are read-only for individual sites. Retention is controlled here. Wallet credits added manually create immutable ledger entries.</p><table class="bbn-table"><thead><tr><th>Site</th><th>Control</th><th>Wallet / Usage</th></tr></thead><tbody>
        <?php foreach($rows as $r): $d=$site_map[(int)$r->blog_id]??null; $name=$d?$d->blogname:('Site '.$r->blog_id);?><tr><td><strong><?php echo esc_html($name);?></strong><br><small>Site #<?php echo (int)$r->blog_id;?></small><br><span class="bbn-status <?php echo !empty($r->own_access_token_enc)?'bbn-ok':'bbn-off';?>">Own Meta: <?php echo !empty($r->own_access_token_enc)?'Connected':'Not connected';?></span></td><td><form method="post" class="bbn-site-form"><?php wp_nonce_field('bb_network_messaging_save','bb_network_messaging_nonce');?><input type="hidden" name="bb_network_action" value="site_policy"><input type="hidden" name="blog_id" value="<?php echo (int)$r->blog_id;?>"><select name="messaging_mode"><option value="disabled" <?php selected($r->messaging_mode,'disabled');?>>Disabled</option><option value="wallet" <?php selected($r->messaging_mode,'wallet');?>>Wallet</option><option value="own" <?php selected($r->messaging_mode,'own');?>>Own Meta</option><option value="either" <?php selected($r->messaging_mode,'either');?>>Either / Site chooses</option></select><input type="number" min="0" max="120" name="retention_months" value="<?php echo (int)$r->retention_months;?>" title="Message log retention months"><input type="number" step="0.0001" min="0" name="utility_override" value="<?php echo esc_attr($r->utility_sell_override);?>" placeholder="Utility ₹"><input type="number" step="0.0001" min="0" name="marketing_override" value="<?php echo esc_attr($r->marketing_sell_override);?>" placeholder="Marketing ₹"><input type="number" step="0.0001" min="0" name="authentication_override" value="<?php echo esc_attr($r->authentication_sell_override);?>" placeholder="Auth ₹"><button class="button">Save</button></form></td><td><strong>₹<?php echo number_format((float)$r->wallet_balance,2);?></strong><br><small><?php echo (int)$r->month_messages;?> messages this month</small><form method="post" class="bbn-credit"><?php wp_nonce_field('bb_network_messaging_save','bb_network_messaging_nonce');?><input type="hidden" name="bb_network_action" value="manual_credit"><input type="hidden" name="blog_id" value="<?php echo (int)$r->blog_id;?>"><input type="number" step="0.01" min="0.01" name="credit_amount" placeholder="Credit ₹" required><input type="text" name="credit_note" placeholder="Reason"><button class="button button-primary">Add Credit</button></form></td></tr><?php endforeach;?>
        </tbody></table></div></div><?php
    }

}
BizSkill_Business_Board::instance();
