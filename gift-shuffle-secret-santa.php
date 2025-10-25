<?php
/*
Plugin Name: Gitf Shuffle & Secret Santa (BeeClear)
Description: Make Secret Santa effortless and fun: this plugin handles fair, conflict-free pair drawing, gives participants a secure anonymous 1:1 chat to swap hints, and streamlines everything with clear user/admin panels. Share gift ideas, track progress, and keep the surprise intact—plus import/export lists, embed with an optional shortcode, and even add affiliate suggestions (Amazon + Allegro) for easy, inspired shopping.
Version: 4.3.0
Author: BeeClear
Author URI: https://beeclear.pl
Text Domain: gift-shuffle-secret-santa
Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** ------------------------------------------------------------------------
 * CONSTANTS & HELPERS
 * ------------------------------------------------------------------------- */
if ( ! defined( 'BCSS_VER' ) )  define( 'BCSS_VER', '4.3.0' );
if ( ! defined( 'BCSS_TD' ) )   define( 'BCSS_TD', 'beeclear-secret-santa' );
function bcss_now_mysql(){ return current_time('mysql'); }
function bcss_pair_key($a,$b){ $a=(int)$a; $b=(int)$b; $p=[$a,$b]; sort($p); return $p[0].':'.$p[1]; }
function bcss_admin_url($slug){ return admin_url('admin.php?page='.$slug); }
function bcss_redirect_or_flag($url,$notice='',$msg=''){
    $url = esc_url_raw($url);
    if(!headers_sent()){ wp_safe_redirect($url); exit; }
    if($notice){ $_GET['bcss_notice']=$notice; }
    if($msg){ $_GET['bcss_msg']=$msg; }
    echo '<script>try{location.replace('.wp_json_encode($url).')}catch(e){}</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url='.esc_attr($url).'"></noscript>';
}
function bcss_supported_currencies(){
    return ['PLN','EUR','USD','GBP'];
}

/** I18n */
add_action('plugins_loaded', function(){
    load_plugin_textdomain(BCSS_TD,false,dirname(plugin_basename(__FILE__)).'/languages');
});

/** ------------------------------------------------------------------------
 * OPTIONS (defaults)
 * ------------------------------------------------------------------------- */
function bcss_default_labels(){
    return [
        'panel_title'               => 'My Gift & Chats',
        'who_i_gift'                => 'You will gift',
        'who_gifts_me'              => 'Who gifts you',
        'their_hints'               => 'Their hints:',
        'your_hints_label'          => 'Your hints to your Gift Shuffle partner:',
        'save_message'              => 'Save message',
        'no_assignment'             => 'You do not have an assigned person yet.',
        'no_hints'                  => 'No hints yet.',
        'giftee_chat_title'         => 'Chat with your giftee',
        'gifter_chat_title'         => 'Chat with your gifter',
        'type_message_placeholder'  => 'Type a message…',
        'send'                      => 'Send',
        'new_message_email_subject' => 'You have a new Gift Shuffle message',
        'new_message_email_intro'   => 'You received a new anonymous message in Gift Shuffle.',
        'open_panel'                => 'Open my panel',
        'draw_started_email_subject'=> 'Gift Shuffle draw started',
        'draw_started_email_body'   => 'Hi! The Gift Shuffle draw has started and you have been assigned someone. Open your panel: {panel_url}',
        'separator_draw'            => '— New draw started on {date} —',
        'separator_reset'           => '— Reset performed on {date} —',
        // Affiliate widget
        'gift_shop_title'           => 'Gift ideas (Affiliate)',
        'gift_shop_hint'            => 'Type a keyword and open partners to browse gifts.',
        'gift_shop_placeholder'     => 'e.g. mug, lego, headphones…',
        'gift_shop_btn_amazon'      => 'Search on Amazon',
        'gift_shop_btn_allegro'     => 'Search on Allegro',
        'gift_shop_disclaimer'      => 'Links may be affiliate. We may earn a commission.',
        // Gift info
        'gift_info_title'           => 'Gift exchange details',
        'gift_info_date_label'      => 'Gift exchange date:',
        'gift_info_budget_label'    => 'Budget:',
        'gift_info_not_set'         => 'Details will appear here once the organizer shares them.',
    ];
}
function bcss_default_affiliate(){
    return [
        'enabled'        => false,
        'show_in_panel'  => true,
        'disclaimer'     => '',
        'amazon' => [
            'enabled' => true,
            'tld'     => 'com',   // com, de, co.uk, fr, es, it, ca, com.au, co.jp, ae, nl, se, pl (brak PL, ale zostawiamy elastyczność)
            'tag'     => '',
            'extra'   => ''       // np. &linkCode=ll2&camp=1789&creative=9325
        ],
        'allegro' => [
            'enabled' => true,
            'deeplink_template' => '', // np. https://twoj-partner.net/deeplink?url={url}&subid=secret-santa
        ],
    ];
}
function bcss_get_options(){
    $defaults = [
        'cleanup_on_deactivation' => false,
        'load_default_styles'     => true,
        'custom_css'              => '',
        'last_draw_date'          => '',
        'last_reset_date'         => '',
        'assigned_pairs'          => [],
        'wipe_messages_on_draw'   => false,
        'season'                  => 1,
        'season_markers'          => [1=>['type'=>'init','time'=>bcss_now_mysql()]],
        'version'                 => BCSS_VER,
        'labels'                  => bcss_default_labels(),
        'affiliate'               => bcss_default_affiliate(),
        'gift_exchange_date'      => '',
        'gift_budget'             => '',
        'gift_budget_currency'    => 'PLN',
    ];
    $opts = get_option('bcss_options',[]);
    if(!is_array($opts)) $opts=[];
    // labels
    $opts['labels'] = isset($opts['labels']) && is_array($opts['labels']) ? array_merge(bcss_default_labels(),$opts['labels']) : bcss_default_labels();
    // season
    if(!isset($opts['season'])) $opts['season']=1;
    if(!isset($opts['season_markers']) || !is_array($opts['season_markers'])) $opts['season_markers']=[1=>['type'=>'init','time'=>bcss_now_mysql()]];
    if(!isset($opts['wipe_messages_on_draw'])) $opts['wipe_messages_on_draw']=false;
    // affiliate
    $opts['affiliate'] = isset($opts['affiliate']) && is_array($opts['affiliate']) ? array_replace_recursive(bcss_default_affiliate(),$opts['affiliate']) : bcss_default_affiliate();
    return array_merge($defaults,$opts);
}
function bcss_update_options($new){
    $opts=bcss_get_options();
    $merged=array_merge($opts,$new);
    if(isset($new['labels'])){ $merged['labels']=array_merge(bcss_default_labels(),(array)$new['labels']); }
    if(isset($new['affiliate'])){ $merged['affiliate']=array_replace_recursive(bcss_default_affiliate(),(array)$new['affiliate']); }
    update_option('bcss_options',$merged);
}
function bcss_get_labels(){ $o=bcss_get_options(); return $o['labels']; }
function bcss_start_new_season($type='draw'){
    $opts=bcss_get_options();
    $opts['season']=max(1,(int)$opts['season'])+1;
    $opts['season_markers'][ $opts['season'] ]=['type'=>$type,'time'=>bcss_now_mysql()];
    update_option('bcss_options',$opts);
    return $opts['season'];
}
function bcss_delete_all_messages(){
    $ids=get_posts(['post_type'=>'bcss_msg','posts_per_page'=>-1,'fields'=>'ids']);
    foreach($ids as $id){ wp_delete_post($id,true); }
}

/** Settings link on Plugins list */
add_filter('plugin_action_links_'.plugin_basename(__FILE__),function($links){
    $links[]='<a href="'.esc_url(bcss_admin_url('bcss_global')).'">'.esc_html__('Settings',BCSS_TD).'</a>';
    return $links;
});

/** CPT for chat messages (no UI) */
add_action('init',function(){
    register_post_type('bcss_msg',[
        'labels'=>['name'=>'Gift Shuffle Messages'],
        'public'=>false,'show_ui'=>false,'supports'=>['editor','author'],
        'rewrite'=>false,'query_var'=>false,
    ]);
});

/** ------------------------------------------------------------------------
 * ADMIN MENUS
 * ------------------------------------------------------------------------- */
add_action('admin_menu',function(){
    add_menu_page(__('Gift Shuffle (BeeClear)',BCSS_TD),__('Gift Shuffle (BeeClear)',BCSS_TD),'read','bcss_dashboard','bcss_render_dashboard_page','dashicons-groups',56);
    add_submenu_page('bcss_dashboard',__('My panel',BCSS_TD),__('My panel',BCSS_TD),'read','bcss_my_panel','bcss_render_my_panel_page');
    add_submenu_page('bcss_dashboard',__('Participants & Draw',BCSS_TD),__('Participants & Draw',BCSS_TD),'manage_options','bcss_participants','bcss_render_participants_page');
    add_submenu_page('bcss_dashboard',__('Global settings',BCSS_TD),__('Global settings',BCSS_TD),'manage_options','bcss_global','bcss_render_global_settings_page');
    add_submenu_page('bcss_dashboard',__('Design',BCSS_TD),__('Design',BCSS_TD),'manage_options','bcss_design','bcss_render_design_page');
    add_submenu_page('bcss_dashboard',__('Affiliate',BCSS_TD),__('Affiliate',BCSS_TD),'manage_options','bcss_affiliate','bcss_render_affiliate_page'); // NEW
    add_submenu_page('bcss_dashboard',__('Import/Export',BCSS_TD),__('Import/Export',BCSS_TD),'manage_options','bcss_import_export','bcss_render_import_export_page');
},9);
add_action('admin_menu',function(){ remove_submenu_page('bcss_dashboard','bcss_dashboard'); },999);

/** ------------------------------------------------------------------------
 * DASHBOARD (info)
 * ------------------------------------------------------------------------- */
function bcss_render_dashboard_page(){
    if(!current_user_can('read')) wp_die(esc_html__('Access denied.',BCSS_TD));
    $labels=bcss_get_labels();
    echo '<div class="wrap"><h1>Gift Shuffle (BeeClear)</h1>';
    echo '<p>'.esc_html__('Use the panel below to manage your Gift Shuffle experience.',BCSS_TD).'</p>';
    echo '<p><a class="button button-primary" href="'.esc_url(bcss_admin_url('bcss_my_panel')).'">'.esc_html($labels['open_panel']).'</a></p>';
    echo '<hr/><p><small>Made by <a href="https://beeclear.pl" target="_blank" rel="noopener">BeeClear</a></small></p></div>';
}

/** ------------------------------------------------------------------------
 * USER PANEL (assignment + chats + hints + affiliate widget)
 * ------------------------------------------------------------------------- */
function bcss_find_gifter_user_id($uid){
    $u=get_users(['meta_key'=>'bcss_target_user','meta_value'=>(int)$uid,'fields'=>['ID'],'number'=>1]);
    if($u && !is_wp_error($u)){ $x=reset($u); return (int)$x->ID; }
    return 0;
}
function bcss_user_can_chat_with($me,$other){
    $other=(int)$other; if($other<=0) return false;
    $target=(int)get_user_meta($me,'bcss_target_user',true);
    $gifter=(int)bcss_find_gifter_user_id($me);
    return ($other===$target || $other===$gifter);
}
function bcss_render_chat_window($me,$other,$wid,$title){
    $labels=bcss_get_labels();
    echo '<div class="bcss-chat-card"><div class="bcss-chat-title">'.esc_html($title).'</div>';
    if(!$other){ echo '<p class="bcss-meta"><em>'.esc_html($labels['no_assignment']).'</em></p></div>'; return; }
    echo '<div class="bcss-chat-messages" id="bcss-chat-messages-'.esc_attr($wid).'" data-other="'.esc_attr($other).'">';
    echo bcss_get_conversation_html($me,$other).'</div>';
    echo '<div class="bcss-chat-input"><input type="text" class="bcss-chat-text" id="bcss-chat-input-'.esc_attr($wid).'" placeholder="'.esc_attr($labels['type_message_placeholder']).'" />';
    echo '<button type="button" class="button bcss-chat-send" data-window="'.esc_attr($wid).'">'.esc_html($labels['send']).'</button></div></div>';
}
function bcss_render_separator_html($marker,$labels){
    $type=isset($marker['type'])?$marker['type']:'draw';
    $time=isset($marker['time'])?$marker['time']:bcss_now_mysql();
    $fmt=get_option('date_format').' '.get_option('time_format');
    $date=date_i18n($fmt,strtotime($time));
    $tpl=($type==='reset')?$labels['separator_reset']:$labels['separator_draw'];
    $text=str_replace('{date}',$date,$tpl);
    return '<div class="bcss-separator"><span>'.esc_html($text).'</span></div>';
}
function bcss_get_conversation_html($me,$other){
    $pair=bcss_pair_key($me,$other);
    $q=new WP_Query([
        'post_type'=>'bcss_msg','posts_per_page'=>100,'orderby'=>'date','order'=>'ASC',
        'meta_key'=>'_bcss_pair_key','meta_value'=>$pair,'no_found_rows'=>true,
    ]);
    $opts=bcss_get_options(); $labels=bcss_get_labels(); $cur=(int)$opts['season'];
    ob_start(); $prev=null; $had=false;
    if($q->have_posts()){
        while($q->have_posts()){ $q->the_post(); $had=true;
            $sender=(int)get_post_meta(get_the_ID(),'_bcss_sender',true);
            $season=(int)get_post_meta(get_the_ID(),'_bcss_season',true); if($season<=0) $season=1;
            if($prev!==null && $season!==$prev){
                echo isset($opts['season_markers'][$season]) ? bcss_render_separator_html($opts['season_markers'][$season],$labels)
                     : bcss_render_separator_html(['type'=>'draw','time'=>get_the_date('Y-m-d H:i:s')],$labels);
            }
            $prev=$season;
            $content=wp_kses_post(get_the_content(null,false,get_the_ID()));
            $when=get_the_date('Y-m-d H:i'); $cls=$sender===$me?'sent':'received';
            echo '<div class="bcss-bubble '.$cls.'"><div class="bcss-bubble-content">'.wpautop($content).'</div><div class="bcss-bubble-time">'.esc_html($when).'</div></div>';
        }
        wp_reset_postdata();
    }
    // natychmiastowy separator nowej rundy
    if($cur>=1){
        if($had){
            if($prev===null) $prev=0;
            if($prev<$cur){
                for($s=$prev+1;$s<=$cur;$s++){
                    if(isset($opts['season_markers'][$s])) echo bcss_render_separator_html($opts['season_markers'][$s],$labels);
                }
            }
        }else{
            if(isset($opts['season_markers'][$cur])) echo bcss_render_separator_html($opts['season_markers'][$cur],$labels);
        }
    }
    return ob_get_clean();
}

/** ---------- Affiliate helpers ---------- */
function bcss_aff_sanitize_tld($tld){
    $tld = strtolower(preg_replace('~[^a-z.]+~','',$tld));
    if(!$tld) $tld='com';
    return $tld;
}
function bcss_aff_build_amazon_url($query){
    $o=bcss_get_options()['affiliate'];
    if(empty($o['amazon']['enabled'])) return '';
    $tld=bcss_aff_sanitize_tld($o['amazon']['tld']);
    $tag=trim($o['amazon']['tag']);
    $extra=ltrim((string)$o['amazon']['extra']);
    $base='https://www.amazon.'.$tld.'/s';
    $q = [
        'k' => $query,
    ];
    if($tag!==''){ $q['tag']=$tag; }
    $url= add_query_arg(array_map('rawurlencode',$q), $base);
    if($extra!==''){
        if($extra[0]!=='&') $extra='&'.$extra;
        $url .= $extra;
    }
    return $url;
}
function bcss_aff_build_allegro_url($query){
    $o=bcss_get_options()['affiliate'];
    if(empty($o['allegro']['enabled'])) return '';
    $target = 'https://allegro.pl/listing?string='.rawurlencode($query);
    $tpl    = trim((string)$o['allegro']['deeplink_template']);
    if($tpl==='') return $target; // brak partnera – bezpośredni link
    // zamień {url} w szablonie
    return str_replace('{url}', rawurlencode($target), $tpl);
}

/** Panel użytkownika */
function bcss_render_my_panel_page(){
    if(!current_user_can('read')) wp_die(esc_html__('Access denied.',BCSS_TD));
    $labels=bcss_get_labels();
    $o=bcss_get_options(); $aff=$o['affiliate'];
    $gift_date_raw = isset($o['gift_exchange_date']) ? $o['gift_exchange_date'] : '';
    $gift_budget_raw = isset($o['gift_budget']) ? $o['gift_budget'] : '';
    $gift_currency = isset($o['gift_budget_currency']) ? strtoupper($o['gift_budget_currency']) : 'PLN';

    $gift_date_display='';
    if($gift_date_raw){
        $timestamp=strtotime($gift_date_raw.' 00:00:00');
        if($timestamp){ $gift_date_display=date_i18n(get_option('date_format'), $timestamp); }
        else { $gift_date_display=$gift_date_raw; }
    }

    $gift_budget_display='';
    if($gift_budget_raw!==''){
        if(is_numeric($gift_budget_raw)){
            $gift_budget_display=number_format_i18n((float)$gift_budget_raw, 2);
        }else{
            $gift_budget_display=$gift_budget_raw;
        }
    }

    $u=wp_get_current_user(); $my=(int)$u->ID;
    $giftee=(int)get_user_meta($my,'bcss_target_user',true);
    $gifter=(int)bcss_find_gifter_user_id($my);

    if(isset($_POST['bcss_save_message'])){
        check_admin_referer('bcss_panel_save_message_action','bcss_panel_save_message_nonce');
        $msg=isset($_POST['bcss_message_to_santa'])?wp_kses_post(wp_unslash($_POST['bcss_message_to_santa'])):'';
        update_user_meta($my,'bcss_message_to_santa',$msg);
        echo '<div class="notice notice-success is-dismissible"><p>'.esc_html__('Message saved.',BCSS_TD).'</p></div>';
    }

    $giftee_user = $giftee ? get_user_by('id',$giftee) : null;
    $their_hints = $giftee_user ? get_user_meta($giftee_user->ID,'bcss_message_to_santa',true) : '';

    ?>
    <style>
    .bcss-panel-wrap{max-width:1200px}
    .bcss-info-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:10px}
    @media(max-width:900px){.bcss-info-grid{grid-template-columns:1fr}}
    .bcss-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px}
    .bcss-card h2{margin-top:0}
    .bcss-meta{color:#6c7781}
    .bcss-info-card{margin-top:16px}
    .bcss-info-card p{margin:0 0 6px}
    .bcss-chat-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    @media(max-width:1200px){.bcss-chat-grid{grid-template-columns:1fr}}
    .bcss-chat-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:0;display:flex;flex-direction:column;overflow:hidden}
    .bcss-chat-title{padding:12px 14px;border-bottom:1px solid #e2e4e7;font-weight:600}
    .bcss-chat-messages{padding:14px;height:380px;overflow-y:auto;background:#f6f7f7}
    .bcss-bubble{max-width:75%;margin:8px 0;padding:8px 12px;border-radius:16px;position:relative}
    .bcss-bubble.sent{margin-left:auto;background:#d1ecf1}
    .bcss-bubble.received{margin-right:auto;background:#e2e3e5}
    .bcss-bubble-time{font-size:11px;color:#6c7781;margin-top:4px;text-align:right}
    .bcss-chat-input{display:flex;gap:8px;border-top:1px solid #e2e4e7;padding:10px;background:#fff}
    .bcss-chat-text{flex:1}
    .bcss-separator{text-align:center;margin:10px 0;color:#6c7781;font-size:12px}
    .bcss-separator span{background:#eef1f4;padding:3px 8px;border-radius:12px;display:inline-block}
    /* Affiliate */
    .bcss-shop{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
    .bcss-shop input[type="text"]{min-width:260px}
    .bcss-shop .button{border-radius:6px}
    .bcss-shop small{display:block;color:#6c7781;margin-top:8px}
    </style>
    <div class="wrap bcss-panel-wrap">
        <h1><?php echo esc_html($labels['panel_title']); ?></h1>

        <div class="bcss-card bcss-info-card">
            <h2><?php echo esc_html($labels['gift_info_title']); ?></h2>
            <?php if($gift_date_display): ?>
                <p><strong><?php echo esc_html($labels['gift_info_date_label']); ?></strong> <?php echo esc_html($gift_date_display); ?></p>
            <?php endif; ?>
            <?php if($gift_budget_display!==''): ?>
                <p><strong><?php echo esc_html($labels['gift_info_budget_label']); ?></strong> <?php echo esc_html(trim($gift_budget_display.' '.$gift_currency)); ?></p>
            <?php endif; ?>
            <?php if(!$gift_date_display && $gift_budget_display===''): ?>
                <p class="bcss-meta"><em><?php echo esc_html($labels['gift_info_not_set']); ?></em></p>
            <?php endif; ?>
        </div>

        <div class="bcss-info-grid">
            <div class="bcss-card">
                <h2><?php echo esc_html($labels['who_i_gift']); ?></h2>
                <?php if($giftee && $giftee_user): ?>
                    <p><strong><?php echo esc_html($giftee_user->display_name); ?></strong></p>
                    <p class="bcss-meta"><?php echo esc_html($labels['their_hints']); ?></p>
                    <?php if($their_hints): ?>
                        <div><?php echo wp_kses_post(wpautop($their_hints)); ?></div>
                    <?php else: ?>
                        <p class="bcss-meta"><em><?php echo esc_html($labels['no_hints']); ?></em></p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="bcss-meta"><em><?php echo esc_html($labels['no_assignment']); ?></em></p>
                <?php endif; ?>
            </div>
            <div class="bcss-card">
                <h2><?php echo esc_html($labels['your_hints_label']); ?></h2>
                <form method="post">
                    <?php wp_nonce_field('bcss_panel_save_message_action','bcss_panel_save_message_nonce'); ?>
                    <textarea name="bcss_message_to_santa" rows="6" style="width:100%;"><?php echo esc_textarea(get_user_meta($my,'bcss_message_to_santa',true)); ?></textarea>
                    <p><button type="submit" name="bcss_save_message" class="button button-primary"><?php echo esc_html($labels['save_message']); ?></button></p>
                </form>
            </div>
        </div>

        <?php if(!empty($aff['enabled']) && !empty($aff['show_in_panel'])): ?>
        <div class="bcss-card" style="margin-top:16px;">
            <h2><?php echo esc_html($labels['gift_shop_title']); ?></h2>
            <p class="bcss-meta"><?php echo esc_html($labels['gift_shop_hint']); ?></p>
            <div class="bcss-shop" data-amazon="<?php echo esc_url(bcss_aff_build_amazon_url('GIFTS')); ?>" data-allegro="<?php echo esc_url(bcss_aff_build_allegro_url('GIFTS')); ?>">
                <input type="text" id="bcss-gift-q" placeholder="<?php echo esc_attr($labels['gift_shop_placeholder']); ?>" />
                <?php if(!empty($aff['amazon']['enabled'])): ?>
                    <a class="button button-primary" id="bcss-gift-amazon" href="#" target="_blank" rel="sponsored nofollow noopener"><?php echo esc_html($labels['gift_shop_btn_amazon']); ?></a>
                <?php endif; ?>
                <?php if(!empty($aff['allegro']['enabled'])): ?>
                    <a class="button" id="bcss-gift-allegro" href="#" target="_blank" rel="sponsored nofollow noopener"><?php echo esc_html($labels['gift_shop_btn_allegro']); ?></a>
                <?php endif; ?>
            </div>
            <?php
            $disc = trim((string)$aff['disclaimer']); if($disc==='') $disc = (string)$labels['gift_shop_disclaimer'];
            if($disc!=='') echo '<small>'.esc_html($disc).'</small>';
            ?>
        </div>
        <?php endif; ?>

        <h2 style="margin-top:18px;"><?php echo esc_html__('Anonymous chats',BCSS_TD); ?></h2>
        <div class="bcss-chat-grid" id="bcss-chat-grid" data-nonce="<?php echo esc_attr(wp_create_nonce('bcss_chat_nonce')); ?>" data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
            <?php bcss_render_chat_window($my,$giftee,'giftee',$labels['giftee_chat_title']); ?>
            <?php bcss_render_chat_window($my,$gifter,'gifter',$labels['gifter_chat_title']); ?>
        </div>
    </div>

    <script>
    (function(){
        // Affiliate widget
        (function(){
            const holder=document.querySelector('.bcss-shop');
            if(!holder) return;
            const q=document.getElementById('bcss-gift-q');
            const aA=document.getElementById('bcss-gift-amazon');
            const aL=document.getElementById('bcss-gift-allegro');
            function build(){
                const query=(q.value||'gift').trim();
                if(aA){
                    const tpl=holder.getAttribute('data-amazon').replace('GIFTS', encodeURIComponent(query));
                    aA.href=tpl;
                }
                if(aL){
                    const tpl=holder.getAttribute('data-allegro').replace('GIFTS', encodeURIComponent(query));
                    aL.href=tpl;
                }
            }
            q.addEventListener('input',build); build();
        })();

        // Chats
        const grid=document.getElementById('bcss-chat-grid'); if(!grid) return;
        const ajax=grid.getAttribute('data-ajax'); const nonce=grid.getAttribute('data-nonce');
        function scrollBottom(box){ if(box){ box.scrollTop=box.scrollHeight; } }
        function send(windowId){
            const box=document.getElementById('bcss-chat-messages-'+windowId); if(!box) return;
            const input=document.getElementById('bcss-chat-input-'+windowId);
            const text=(input.value||'').trim(); if(!text) return;
            const other=box.getAttribute('data-other'); if(!other||other==='0') return;
            const data=new FormData(); data.append('action','bcss_send_message'); data.append('security',nonce); data.append('other_id',other); data.append('window',windowId); data.append('message',text);
            fetch(ajax,{method:'POST',credentials:'same-origin',body:data}).then(r=>r.json()).then(j=>{
                if(j&&j.ok){ box.innerHTML=j.html; input.value=''; scrollBottom(box); } else if(j&&j.error){ alert(j.error); }
            }).catch(()=>{});
        }
        function fetchConv(windowId){
            const box=document.getElementById('bcss-chat-messages-'+windowId); if(!box) return;
            const other=box.getAttribute('data-other'); if(!other||other==='0') return;
            const params=new URLSearchParams(); params.append('action','bcss_fetch_conversation'); params.append('security',nonce); params.append('other_id',other);
            fetch(ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:params.toString()})
                .then(r=>r.json()).then(j=>{ if(j&&j.ok){ box.innerHTML=j.html; scrollBottom(box); } }).catch(()=>{});
        }
        ['giftee','gifter'].forEach(function(w){
            const btn=document.querySelector('.bcss-chat-send[data-window="'+w+'"]');
            const input=document.getElementById('bcss-chat-input-'+w);
            const box=document.getElementById('bcss-chat-messages-'+w);
            if(btn) btn.addEventListener('click',function(){ send(w); });
            if(input) input.addEventListener('keypress',function(e){ if(e.key==='Enter'){ e.preventDefault(); send(w); }});
            if(box) scrollBottom(box);
            setTimeout(function(){ fetchConv(w); },300);
        });
        setInterval(function(){ ['giftee','gifter'].forEach(fetchConv); },12000);
    })();
    </script>
    <?php
}

/** ------------------------------------------------------------------------
 * AJAX: chats
 * ------------------------------------------------------------------------- */
add_action('wp_ajax_bcss_send_message','bcss_ajax_send_message');
function bcss_ajax_send_message(){
    if(!is_user_logged_in()) wp_send_json(['ok'=>false,'error'=>__('Not logged in.',BCSS_TD)]);
    check_ajax_referer('bcss_chat_nonce','security');
    $me=(int)get_current_user_id();
    $other=isset($_POST['other_id'])?(int)$_POST['other_id']:0;
    $message=isset($_POST['message'])?wp_kses_post(wp_unslash($_POST['message'])):'';
    if(!$other || !bcss_user_can_chat_with($me,$other)) wp_send_json(['ok'=>false,'error'=>__('You cannot message this user.',BCSS_TD)]);
    if(''===trim(wp_strip_all_tags($message))) wp_send_json(['ok'=>false,'error'=>__('Empty message.',BCSS_TD)]);
    $pid=wp_insert_post(['post_type'=>'bcss_msg','post_status'=>'publish','post_author'=>$me,'post_content'=>$message,'post_title'=>'msg-'.time()],true);
    if(is_wp_error($pid)) wp_send_json(['ok'=>false,'error'=>__('Could not save message.',BCSS_TD)]);
    $opts=bcss_get_options();
    add_post_meta($pid,'_bcss_sender',$me);
    add_post_meta($pid,'_bcss_recipient',$other);
    add_post_meta($pid,'_bcss_pair_key',bcss_pair_key($me,$other));
    add_post_meta($pid,'_bcss_season',(int)$opts['season']);
    // mail notify
    $labels=bcss_get_labels(); $recipient=get_user_by('id',$other);
    if($recipient && is_email($recipient->user_email)){
        $subject=$labels['new_message_email_subject']; $panel=bcss_admin_url('bcss_my_panel');
        $body=$labels['new_message_email_intro']."\n\n".$panel;
        wp_mail($recipient->user_email,$subject,$body);
    }
    $html=bcss_get_conversation_html($me,$other);
    wp_send_json(['ok'=>true,'html'=>$html]);
}
add_action('wp_ajax_bcss_fetch_conversation','bcss_ajax_fetch_conversation');
function bcss_ajax_fetch_conversation(){
    if(!is_user_logged_in()) wp_send_json(['ok'=>false]);
    check_ajax_referer('bcss_chat_nonce','security');
    $me=(int)get_current_user_id(); $other=isset($_POST['other_id'])?(int)$_POST['other_id']:0;
    if(!$other || !bcss_user_can_chat_with($me,$other)) wp_send_json(['ok'=>false]);
    $html=bcss_get_conversation_html($me,$other);
    wp_send_json(['ok'=>true,'html'=>$html]);
}

/** ------------------------------------------------------------------------
 * PARTICIPANTS & DRAW (PRG + emails)
 * ------------------------------------------------------------------------- */
function bcss_render_participants_page(){
    if(!current_user_can('manage_options')) wp_die(esc_html__('Access denied.',BCSS_TD));
    $url=bcss_admin_url('bcss_participants');

    if(isset($_POST['bcss_save_participation'])){
        check_admin_referer('bcss_save_participation_action','bcss_save_participation_nonce');
        foreach(get_users(['fields'=>['ID']]) as $u){
            update_user_meta($u->ID,'bcss_participates', isset($_POST['bcss_participates'][$u->ID])?'1':'0');
        }
        bcss_redirect_or_flag(add_query_arg('bcss_notice','saved_participation',$url),'saved_participation');
    }
    if(isset($_POST['bcss_perform_lottery'])){
        check_admin_referer('bcss_perform_lottery_action','bcss_perform_lottery_nonce');
        $r=bcss_perform_lottery();
        if(is_wp_error($r)){ $m=$r->get_error_message(); bcss_redirect_or_flag(add_query_arg(['bcss_notice'=>'draw_err','bcss_msg'=>rawurlencode($m)],$url),'draw_err',$m); }
        else{ bcss_redirect_or_flag(add_query_arg('bcss_notice','draw_ok',$url),'draw_ok'); }
    }
    if(isset($_POST['bcss_reset_lottery'])){
        check_admin_referer('bcss_reset_lottery_action','bcss_reset_lottery_nonce');
        bcss_reset_lottery();
        bcss_redirect_or_flag(add_query_arg('bcss_notice','reset_ok',$url),'reset_ok');
    }

    if(isset($_GET['bcss_notice'])){
        $n=sanitize_key($_GET['bcss_notice']);
        if('saved_participation'===$n) echo '<div class="notice notice-success is-dismissible"><p>'.esc_html__('Participation settings saved.',BCSS_TD).'</p></div>';
        if('draw_ok'===$n) echo '<div class="notice notice-success is-dismissible"><p>'.esc_html__('Lottery executed successfully.',BCSS_TD).'</p></div>';
        if('draw_err'===$n){ $m=isset($_GET['bcss_msg'])?sanitize_text_field(wp_unslash($_GET['bcss_msg'])):__('Error.',BCSS_TD); echo '<div class="notice notice-error is-dismissible"><p>'.esc_html($m).'</p></div>'; }
        if('reset_ok'===$n) echo '<div class="notice notice-warning is-dismissible"><p>'.esc_html__('Lottery has been reset.',BCSS_TD).'</p></div>';
    }

    $opts=bcss_get_options();
    echo '<div class="wrap"><h1>'.esc_html__('Participants & Draw',BCSS_TD).'</h1>';

    echo '<form method="post" action="">';
    wp_nonce_field('bcss_perform_lottery_action','bcss_perform_lottery_nonce');
    echo '<button type="submit" name="bcss_perform_lottery" class="button button-primary">'.esc_html__('Run lottery',BCSS_TD).'</button> ';
    wp_nonce_field('bcss_reset_lottery_action','bcss_reset_lottery_nonce');
    echo '<button type="submit" name="bcss_reset_lottery" class="button">'.esc_html__('Reset',BCSS_TD).'</button>';
    echo '</form>';

    echo '<h3>'.esc_html__('Last draw/reset',BCSS_TD).'</h3>';
    echo !empty($opts['last_draw_date'])?'<p>'.sprintf(esc_html__('Last draw: %s',BCSS_TD),'<strong>'.esc_html($opts['last_draw_date']).'</strong>').'</p>':'<p><em>'.esc_html__('No draw has been performed yet.',BCSS_TD).'</em></p>';
    echo !empty($opts['last_reset_date'])?'<p>'.sprintf(esc_html__('Last reset: %s',BCSS_TD),'<strong>'.esc_html($opts['last_reset_date']).'</strong>').'</p>':'<p><em>'.esc_html__('Reset has not been performed yet.',BCSS_TD).'</em></p>';
    echo '<hr/>';

    echo '<h2 class="title">'.esc_html__('Participants',BCSS_TD).'</h2>';
    echo '<form method="post" action="">';
    wp_nonce_field('bcss_save_participation_action','bcss_save_participation_nonce');
    echo '<table class="widefat fixed striped"><thead><tr><th>'.esc_html__('User',BCSS_TD).'</th><th>'.esc_html__('Email',BCSS_TD).'</th><th>'.esc_html__('Participates',BCSS_TD).'</th><th>'.esc_html__('Assigned to gift',BCSS_TD).'</th></tr></thead><tbody>';
    foreach(get_users() as $user){
        $part=get_user_meta($user->ID,'bcss_participates',true);
        $target=(int)get_user_meta($user->ID,'bcss_target_user',true);
        $name=''; if($target){ $tu=get_user_by('id',$target); $name=$tu?$tu->display_name:''; }
        echo '<tr><td>'.esc_html($user->display_name).'</td><td>'.esc_html($user->user_email).'</td><td><label><input type="checkbox" name="bcss_participates['.esc_attr($user->ID).']" value="1" '.checked($part,'1',false).' /> '.esc_html__('Yes',BCSS_TD).'</label></td><td><input type="text" readonly class="regular-text" value="'.esc_attr($name).'" /></td></tr>';
    }
    echo '</tbody></table><p><button type="submit" name="bcss_save_participation" class="button button-primary">'.esc_html__('Save changes',BCSS_TD).'</button></p></form>';

    $pairs=isset($opts['assigned_pairs']) && is_array($opts['assigned_pairs'])?$opts['assigned_pairs']:[];
    echo '<h2 class="title">'.esc_html__('Lottery results',BCSS_TD).'</h2>';
    if($pairs){ echo '<ul>'; foreach($pairs as $p){ echo '<li>'.esc_html($p).'</li>'; } echo '</ul>'; } else { echo '<p><em>'.esc_html__('No lottery has been performed yet.',BCSS_TD).'</em></p>'; }
    echo '</div>';
}
function bcss_perform_lottery(){
    if(!current_user_can('manage_options')) return new WP_Error('bcss_perm',__('Access denied.',BCSS_TD));
    $participants=get_users(['meta_key'=>'bcss_participates','meta_value'=>'1']);
    $n=is_array($participants)?count($participants):0;
    if($n<2) return new WP_Error('bcss_not_enough',__('At least two participants are required to run the lottery.',BCSS_TD));

    $opts=bcss_get_options();
    if(!empty($opts['wipe_messages_on_draw'])) bcss_delete_all_messages();
    bcss_start_new_season('draw');

    shuffle($participants);
    $pairs=[];
    foreach($participants as $i=>$giver){
        $target=$participants[($i+1)%$n];
        update_user_meta($giver->ID,'bcss_target_user',$target->ID);
        $pairs[] = sprintf(__('%1$s => %2$s',BCSS_TD), $giver->display_name, $target->display_name);
    }
    bcss_update_options(['last_draw_date'=>bcss_now_mysql(),'assigned_pairs'=>$pairs]);

    // notify participants about draw started
    $labels=bcss_get_labels(); $subject=$labels['draw_started_email_subject']; $panel=bcss_admin_url('bcss_my_panel');
    $body=str_replace(['{panel_url}','{site_name}'],[$panel,wp_specialchars_decode(get_bloginfo('name'),ENT_QUOTES)],$labels['draw_started_email_body']);
    foreach($participants as $giver){ if(is_email($giver->user_email)) wp_mail($giver->user_email,$subject,$body); }
    return true;
}
function bcss_reset_lottery(){
    if(!current_user_can('manage_options')) return;
    $opts=bcss_get_options();
    if(!empty($opts['wipe_messages_on_draw'])) bcss_delete_all_messages();
    bcss_start_new_season('reset');
    foreach(get_users(['fields'=>['ID']]) as $u){ delete_user_meta($u->ID,'bcss_target_user'); }
    $opts['last_reset_date']=bcss_now_mysql(); $opts['assigned_pairs']=[];
    update_option('bcss_options',$opts);
}

/** ------------------------------------------------------------------------
 * GLOBAL SETTINGS
 * ------------------------------------------------------------------------- */
function bcss_render_global_settings_page(){
    if(!current_user_can('manage_options')) wp_die(esc_html__('Access denied.',BCSS_TD));
    $opts=bcss_get_options(); $labels=$opts['labels'];

    if(isset($_POST['bcss_save_global'])){
        check_admin_referer('bcss_save_global_action','bcss_save_global_nonce');
        $cleanup=!empty($_POST['bcss_cleanup_on_deactivation']);
        $wipe=!empty($_POST['bcss_wipe_messages_on_draw']);

        $gift_date=isset($_POST['bcss_gift_exchange_date']) ? sanitize_text_field(wp_unslash($_POST['bcss_gift_exchange_date'])) : '';
        if($gift_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$gift_date)){ $gift_date=''; }

        $gift_budget=isset($_POST['bcss_gift_budget']) ? sanitize_text_field(wp_unslash($_POST['bcss_gift_budget'])) : '';
        if($gift_budget!==''){
            $gift_budget=str_replace(',','.', $gift_budget);
            if(!is_numeric($gift_budget)){ $gift_budget=''; }
        }

        $gift_currency = isset($_POST['bcss_gift_currency']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['bcss_gift_currency']))) : 'PLN';
        $currencies=bcss_supported_currencies();
        if(!in_array($gift_currency,$currencies,true)){ $gift_currency=reset($currencies); }

        $new_labels=bcss_default_labels();
        foreach($new_labels as $k=>$v){ if(isset($_POST['bcss_label_'.$k])) $new_labels[$k]=sanitize_text_field(wp_unslash($_POST['bcss_label_'.$k])); }
        bcss_update_options([
            'cleanup_on_deactivation'=>$cleanup,
            'wipe_messages_on_draw'=>$wipe,
            'labels'=>$new_labels,
            'gift_exchange_date'=>$gift_date,
            'gift_budget'=>$gift_budget,
            'gift_budget_currency'=>$gift_currency,
        ]);
        echo '<div class="notice notice-success is-dismissible"><p>'.esc_html__('Settings saved.',BCSS_TD).'</p></div>';
        $opts=bcss_get_options(); $labels=$opts['labels'];
    }
    if(isset($_POST['bcss_clear_all_data'])){
        check_admin_referer('bcss_clear_all_data_action','bcss_clear_all_data_nonce');
        bcss_clear_all_data();
        echo '<div class="notice notice-warning is-dismissible"><p>'.esc_html__('All plugin data has been cleared.',BCSS_TD).'</p></div>';
        $opts=bcss_get_options(); $labels=$opts['labels'];
    }

    echo '<div class="wrap"><h1>'.esc_html__('Global settings',BCSS_TD).'</h1>';
    echo '<form method="post" action="">'; wp_nonce_field('bcss_save_global_action','bcss_save_global_nonce');
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th>'.esc_html__('Clean data on deactivation',BCSS_TD).'</th><td><label><input type="checkbox" name="bcss_cleanup_on_deactivation" value="1" '.checked(!empty($opts['cleanup_on_deactivation']),true,false).' /> '.esc_html__('Remove all plugin data when deactivated.',BCSS_TD).'</label></td></tr>';
    echo '<tr><th>'.esc_html__('Delete conversations on draw/reset',BCSS_TD).'</th><td><label><input type="checkbox" name="bcss_wipe_messages_on_draw" value="1" '.checked(!empty($opts['wipe_messages_on_draw']),true,false).' /> '.esc_html__('If enabled, all chat messages are removed when you run the draw or reset.',BCSS_TD).'</label></td></tr>';
    echo '</tbody></table>';

    $currencies=bcss_supported_currencies();
    echo '<h2>'.esc_html__('Gift exchange information',BCSS_TD).'</h2>';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th>'.esc_html__('Gift exchange date',BCSS_TD).'</th><td><input type="date" name="bcss_gift_exchange_date" value="'.esc_attr($opts['gift_exchange_date']).'" /> <p class="description">'.esc_html__('Participants will see this date in their panel.',BCSS_TD).'</p></td></tr>';
    echo '<tr><th>'.esc_html__('Budget per person',BCSS_TD).'</th><td>';
    echo '<input type="number" min="0" step="0.01" name="bcss_gift_budget" value="'.esc_attr($opts['gift_budget']).'" style="width:120px;" /> ';
    echo '<select name="bcss_gift_currency">';
    foreach($currencies as $currency){
        echo '<option value="'.esc_attr($currency).'" '.selected($opts['gift_budget_currency'],$currency,false).'>'.esc_html($currency).'</option>';
    }
    echo '</select>';
    echo '<p class="description">'.esc_html__('Define the recommended gift budget and currency shown to participants.',BCSS_TD).'</p>';
    echo '</td></tr>';
    echo '</tbody></table>';

    echo '<h2>'.esc_html__('Interface labels (for translations)',BCSS_TD).'</h2><p class="description">'.esc_html__('Change UI texts used in the user panel, chats and emails.',BCSS_TD).'</p>';
    echo '<table class="form-table" role="presentation"><tbody>';
    foreach(bcss_default_labels() as $key=>$def){
        echo '<tr><th><label for="bcss_label_'.esc_attr($key).'">'.esc_html($key).'</label></th>';
        echo '<td><input type="text" id="bcss_label_'.esc_attr($key).'" name="bcss_label_'.esc_attr($key).'" class="regular-text" value="'.esc_attr(isset($labels[$key])?$labels[$key]:$def).'"/></td></tr>';
    }
    echo '</tbody></table><p><button type="submit" name="bcss_save_global" class="button button-primary">'.esc_html__('Save settings',BCSS_TD).'</button></p></form>';

    echo '<hr/><h2>'.esc_html__('Danger zone',BCSS_TD).'</h2>';
    echo '<form method="post" action="" onsubmit="return confirm(\''.esc_js(__('This will remove assignments, chats, history and user meta saved by the plugin. Are you sure?',BCSS_TD)).'\');">';
    wp_nonce_field('bcss_clear_all_data_action','bcss_clear_all_data_nonce');
    echo '<button type="submit" name="bcss_clear_all_data" class="button button-secondary">'.esc_html__('Clear all data now',BCSS_TD).'</button></form></div>';
}
function bcss_clear_all_data(){
    if(!current_user_can('manage_options')) return;
    foreach(get_users(['fields'=>['ID']]) as $u){
        delete_user_meta($u->ID,'bcss_participates');
        delete_user_meta($u->ID,'bcss_message_to_santa');
        delete_user_meta($u->ID,'bcss_target_user');
    }
    delete_option('bcss_options'); bcss_delete_all_messages();
}

/** ------------------------------------------------------------------------
 * AFFILIATE SETTINGS PAGE
 * ------------------------------------------------------------------------- */
function bcss_render_affiliate_page(){
    if(!current_user_can('manage_options')) wp_die(esc_html__('Access denied.',BCSS_TD));
    $opts=bcss_get_options(); $aff=$opts['affiliate'];

    if(isset($_POST['bcss_save_aff'])){
        check_admin_referer('bcss_save_aff_action','bcss_save_aff_nonce');
        $aff_new=[
            'enabled'       => !empty($_POST['bcss_aff_enabled']),
            'show_in_panel' => !empty($_POST['bcss_aff_show_panel']),
            'disclaimer'    => sanitize_text_field(wp_unslash($_POST['bcss_aff_disclaimer'] ?? '')),
            'amazon' => [
                'enabled' => !empty($_POST['bcss_aff_amz_enabled']),
                'tld'     => bcss_aff_sanitize_tld($_POST['bcss_aff_amz_tld'] ?? 'com'),
                'tag'     => sanitize_text_field(wp_unslash($_POST['bcss_aff_amz_tag'] ?? '')),
                'extra'   => sanitize_text_field(wp_unslash($_POST['bcss_aff_amz_extra'] ?? '')),
            ],
            'allegro' => [
                'enabled' => !empty($_POST['bcss_aff_alg_enabled']),
                'deeplink_template' => trim(wp_unslash($_POST['bcss_aff_alg_tpl'] ?? '')),
            ],
        ];
        bcss_update_options(['affiliate'=>$aff_new]);
        echo '<div class="notice notice-success is-dismissible"><p>'.esc_html__('Affiliate settings saved.',BCSS_TD).'</p></div>';
        $opts=bcss_get_options(); $aff=$opts['affiliate'];
    }

    echo '<div class="wrap"><h1>'.esc_html__('Affiliate',BCSS_TD).'</h1>';
    echo '<form method="post" action="">'; wp_nonce_field('bcss_save_aff_action','bcss_save_aff_nonce');

    echo '<h2 class="title">'.esc_html__('General',BCSS_TD).'</h2>';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th>'.esc_html__('Enable affiliate module',BCSS_TD).'</th><td><label><input type="checkbox" name="bcss_aff_enabled" value="1" '.checked(!empty($aff['enabled']),true,false).'/> '.esc_html__('Turn on affiliate features',BCSS_TD).'</label></td></tr>';
    echo '<tr><th>'.esc_html__('Show in My panel',BCSS_TD).'</th><td><label><input type="checkbox" name="bcss_aff_show_panel" value="1" '.checked(!empty($aff['show_in_panel']),true,false).'/> '.esc_html__('Display the Gift ideas (Affiliate) card to users',BCSS_TD).'</label></td></tr>';
    echo '<tr><th>'.esc_html__('Disclaimer text',BCSS_TD).'</th><td><input type="text" class="regular-text" name="bcss_aff_disclaimer" value="'.esc_attr($aff['disclaimer']).'" placeholder="'.esc_attr__('Links may be affiliate. We may earn a commission.',BCSS_TD).'"/></td></tr>';
    echo '</tbody></table>';

    echo '<h2 class="title">Amazon</h2>';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th>'.esc_html__('Enable Amazon',BCSS_TD).'</th><td><label><input type="checkbox" name="bcss_aff_amz_enabled" value="1" '.checked(!empty($aff['amazon']['enabled']),true,false).'/> '.esc_html__('Generate Amazon affiliate search links',BCSS_TD).'</label></td></tr>';
    echo '<tr><th>'.esc_html__('Amazon TLD (region)',BCSS_TD).'</th><td><input type="text" name="bcss_aff_amz_tld" value="'.esc_attr($aff['amazon']['tld']).'" class="small-text"/> <span class="description">'.esc_html__('e.g. com, de, co.uk, fr, es, it, ca, com.au, co.jp, ae',BCSS_TD).'</span></td></tr>';
    echo '<tr><th>'.esc_html__('Associate tag',BCSS_TD).'</th><td><input type="text" name="bcss_aff_amz_tag" value="'.esc_attr($aff['amazon']['tag']).'" class="regular-text"/></td></tr>';
    echo '<tr><th>'.esc_html__('Extra query params (optional)',BCSS_TD).'</th><td><input type="text" name="bcss_aff_amz_extra" value="'.esc_attr($aff['amazon']['extra']).'" class="regular-text"/> <span class="description">'.esc_html__('Will be appended to the URL, e.g. &linkCode=ll2',BCSS_TD).'</span></td></tr>';
    echo '</tbody></table>';

    echo '<h2 class="title">Allegro</h2>';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th>'.esc_html__('Enable Allegro',BCSS_TD).'</th><td><label><input type="checkbox" name="bcss_aff_alg_enabled" value="1" '.checked(!empty($aff['allegro']['enabled']),true,false).'/> '.esc_html__('Generate Allegro affiliate/deeplink search links',BCSS_TD).'</label></td></tr>';
    echo '<tr><th>'.esc_html__('Deeplink template',BCSS_TD).'</th><td><input type="text" name="bcss_aff_alg_tpl" value="'.esc_attr($aff['allegro']['deeplink_template']).'" class="regular-text" placeholder="https://partner.net/deeplink?url={url}&subid=secret-santa"/><p class="description">'.esc_html__('Paste your network deeplink pattern with {url} token. If empty, direct Allegro links are used.',BCSS_TD).'</p></td></tr>';
    echo '</tbody></table>';

    echo '<p><button type="submit" name="bcss_save_aff" class="button button-primary">'.esc_html__('Save affiliate settings',BCSS_TD).'</button></p>';
    echo '</form></div>';
}

/** ------------------------------------------------------------------------
 * DESIGN (frontend)
 * ------------------------------------------------------------------------- */
function bcss_default_front_css(){
return <<<CSS
/* Default Secret Santa styles */
.bcss-wrapper{max-width:720px;margin:1.5rem auto;padding:1rem;border:1px solid #ddd;border-radius:8px}
.bcss-card{background:#fff;padding:1rem;border-radius:8px;border:1px solid #eee}
.bcss-title{margin:0 0 .5rem;font-size:1.25rem}
.bcss-meta{color:#666;margin:0 0 1rem}
.bcss-hints{margin:.5rem 0}
.bcss-form{margin-top:1rem}
.bcss-button{display:inline-block;padding:.5rem .9rem;border:1px solid #ddd;border-radius:4px;text-decoration:none}
CSS;
}
function bcss_render_design_page(){
    if(!current_user_can('manage_options')) wp_die(esc_html__('Access denied.',BCSS_TD));
    $opts=bcss_get_options();
    if(isset($_POST['bcss_save_design'])){
        check_admin_referer('bcss_save_design_action','bcss_save_design_nonce');
        $load=!empty($_POST['bcss_load_default_styles']); $css=isset($_POST['bcss_custom_css'])?wp_unslash($_POST['bcss_custom_css']):'';
        bcss_update_options(['load_default_styles'=>$load?true:false,'custom_css'=>(string)$css]);
        echo '<div class="notice notice-success is-dismissible"><p>'.esc_html__('Design settings saved.',BCSS_TD).'</p></div>';
        $opts=bcss_get_options();
    }
    $default=bcss_default_front_css();
    $helpers="/* You can use these empty helper classes in your Custom CSS */\n.bcss-wrapper{}\n.bcss-card{}\n.bcss-title{}\n.bcss-meta{}\n.bcss-hints{}\n.bcss-form{}\n.bcss-button{}";
    echo '<div class="wrap"><h1>'.esc_html__('Design',BCSS_TD).'</h1><p>'.esc_html__('These settings affect the optional frontend shortcode output.',BCSS_TD).'</p>';
    echo '<form method="post" action="">'; wp_nonce_field('bcss_save_design_action','bcss_save_design_nonce');
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th>'.esc_html__('Load default styles',BCSS_TD).'</th><td><label><input type="checkbox" name="bcss_load_default_styles" value="1" '.checked(!empty($opts['load_default_styles']),true,false).'/> '.esc_html__('Enable default plugin styles on the frontend.',BCSS_TD).'</label></td></tr>';
    echo '<tr><th>'.esc_html__('Custom CSS',BCSS_TD).'</th><td><textarea name="bcss_custom_css" rows="10" style="width:100%;">'.esc_textarea($opts['custom_css']).'</textarea><p class="description">'.esc_html__('Add your own CSS (loaded after default styles).',BCSS_TD).'</p></td></tr>';
    echo '<tr><th>'.esc_html__('Available helper classes',BCSS_TD).'</th><td><textarea readonly rows="8" style="width:100%;">'.esc_textarea($helpers).'</textarea></td></tr>';
    echo '<tr><th>'.esc_html__('Default styles (read-only preview)',BCSS_TD).'</th><td><textarea readonly rows="12" style="width:100%;">'.esc_textarea($default).'</textarea></td></tr>';
    echo '</tbody></table><p><button type="submit" name="bcss_save_design" class="button button-primary">'.esc_html__('Save design',BCSS_TD).'</button></p></form></div>';
}

/** ------------------------------------------------------------------------
 * IMPORT / EXPORT
 * ------------------------------------------------------------------------- */
function bcss_render_import_export_page(){
    if(!current_user_can('manage_options')) wp_die(esc_html__('Access denied.',BCSS_TD));
    if(isset($_POST['bcss_import'])){
        check_admin_referer('bcss_import_action','bcss_import_nonce');
        $json=''; if(!empty($_POST['bcss_import_text'])){ $json=wp_unslash($_POST['bcss_import_text']); }
        elseif(!empty($_FILES['bcss_import_file']['tmp_name'])){ $json=file_get_contents($_FILES['bcss_import_file']['tmp_name']); }
        $res=bcss_import_data_from_json($json);
        if(is_wp_error($res)) echo '<div class="notice notice-error is-dismissible"><p>'.esc_html($res->get_error_message()).'</p></div>';
        else echo '<div class="notice notice-success is-dismissible"><p>'.esc_html__('Data imported successfully.',BCSS_TD).'</p></div>';
    }
    $export=bcss_build_export_json();
    echo '<div class="wrap"><h1>'.esc_html__('Import/Export',BCSS_TD).'</h1>';
    echo '<h2>'.esc_html__('Export',BCSS_TD).'</h2><p>'.esc_html__('Copy the JSON below or download it as a file.',BCSS_TD).'</p>';
    echo '<textarea readonly rows="14" style="width:100%;">'.esc_textarea($export).'</textarea>';
    $name='bcss-secret-santa-export-'.sanitize_title(get_bloginfo('name')).'-'.date_i18n('Ymd-His').'.json';
    $data='data:application/json;charset=utf-8,'.rawurlencode($export);
    echo '<p><a class="button" href="'.esc_url($data).'" download="'.esc_attr($name).'">'.esc_html__('Download JSON',BCSS_TD).'</a></p><hr/>';
    echo '<h2>'.esc_html__('Import',BCSS_TD).'</h2><form method="post" action="" enctype="multipart/form-data">';
    wp_nonce_field('bcss_import_action','bcss_import_nonce');
    echo '<p><label>'.esc_html__('Paste JSON',BCSS_TD).'</label><br/><textarea name="bcss_import_text" rows="8" style="width:100%;"></textarea></p>';
    echo '<p><label>'.esc_html__('or upload JSON file',BCSS_TD).'</label><br/><input type="file" name="bcss_import_file" accept="application/json" /></p>';
    echo '<p><button type="submit" name="bcss_import" class="button button-primary">'.esc_html__('Import',BCSS_TD).'</button></p></form></div>';
}
function bcss_build_export_json(){
    $opts=bcss_get_options(); $users_data=[];
    foreach(get_users() as $u){
        $users_data[]=['ID'=>(int)$u->ID,'user_email'=>$u->user_email,'display_name'=>$u->display_name,
            'bcss_participates'=>get_user_meta($u->ID,'bcss_participates',true),
            'bcss_message'=>get_user_meta($u->ID,'bcss_message_to_santa',true),
            'bcss_target_user'=>(int)get_user_meta($u->ID,'bcss_target_user',true)];
    }
    $msgs=get_posts(['post_type'=>'bcss_msg','posts_per_page'=>500,'orderby'=>'date','order'=>'ASC']);
    $messages=[]; foreach($msgs as $m){
        $messages[]=['date'=>get_post_time('mysql',true,$m),'sender'=>(int)get_post_meta($m->ID,'_bcss_sender',true),
            'recipient'=>(int)get_post_meta($m->ID,'_bcss_recipient',true),'pair_key'=>(string)get_post_meta($m->ID,'_bcss_pair_key',true),
            'content'=>$m->post_content,'season'=>(int)get_post_meta($m->ID,'_bcss_season',true)];
    }
    $payload=['plugin'=>'Secret Santa Lottery for WordPress (BeeClear)','version'=>BCSS_VER,'site'=>home_url(),'options'=>$opts,'users'=>$users_data,'messages'=>$messages,'exported_at'=>bcss_now_mysql()];
    $json=wp_json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE); return $json?$json:'{}';
}
function bcss_import_data_from_json($json){
    if(!current_user_can('manage_options')) return new WP_Error('bcss_perm',__('Access denied.',BCSS_TD));
    if(empty($json)) return new WP_Error('bcss_empty',__('No JSON provided.',BCSS_TD));
    $data=json_decode($json,true); if(!is_array($data)) return new WP_Error('bcss_json',__('Invalid JSON.',BCSS_TD));
    if(isset($data['options']) && is_array($data['options'])){
        $o=$data['options']; $safe=[
            'cleanup_on_deactivation'=>!empty($o['cleanup_on_deactivation']),
            'load_default_styles'=>!empty($o['load_default_styles']),
            'custom_css'=>isset($o['custom_css'])?(string)$o['custom_css']:'',
            'last_draw_date'=>isset($o['last_draw_date'])?(string)$o['last_draw_date']:'',
            'last_reset_date'=>isset($o['last_reset_date'])?(string)$o['last_reset_date']:'',
            'assigned_pairs'=>isset($o['assigned_pairs'])&&is_array($o['assigned_pairs'])?array_map('sanitize_text_field',$o['assigned_pairs']):[],
            'season'=>isset($o['season'])?(int)$o['season']:1,
            'season_markers'=>isset($o['season_markers'])&&is_array($o['season_markers'])?$o['season_markers']:[1=>['type'=>'init','time'=>bcss_now_mysql()]],
            'wipe_messages_on_draw'=>!empty($o['wipe_messages_on_draw']),
            'version'=>BCSS_VER,
        ];
        if(isset($o['labels'])&&is_array($o['labels'])) $safe['labels']=array_merge(bcss_default_labels(),$o['labels']);
        if(isset($o['affiliate'])&&is_array($o['affiliate'])) $safe['affiliate']=array_replace_recursive(bcss_default_affiliate(),$o['affiliate']);
        bcss_update_options($safe);
    }
    if(isset($data['users'])&&is_array($data['users'])){
        foreach($data['users'] as $ud){
            if(!isset($ud['ID'])) continue; $uid=(int)$ud['ID'];
            if(get_user_by('id',$uid)){
                if(isset($ud['bcss_participates'])) update_user_meta($uid,'bcss_participates',$ud['bcss_participates']?'1':'0');
                if(isset($ud['bcss_message'])) update_user_meta($uid,'bcss_message_to_santa',wp_kses_post($ud['bcss_message']));
                if(isset($ud['bcss_target_user'])) update_user_meta($uid,'bcss_target_user',(int)$ud['bcss_target_user']);
            }
        }
    }
    if(isset($data['messages'])&&is_array($data['messages'])){
        foreach($data['messages'] as $m){
            $sender=(int)($m['sender']??0); $recipient=(int)($m['recipient']??0); $content=wp_kses_post($m['content']??'');
            if($sender && $recipient && $content){
                $pid=wp_insert_post(['post_type'=>'bcss_msg','post_status'=>'publish','post_author'=>$sender,'post_content'=>$content,'post_title'=>'imported-msg','post_date'=>$m['date']??bcss_now_mysql()],true);
                if(!is_wp_error($pid)){
                    add_post_meta($pid,'_bcss_sender',$sender); add_post_meta($pid,'_bcss_recipient',$recipient);
                    add_post_meta($pid,'_bcss_pair_key',$m['pair_key']??bcss_pair_key($sender,$recipient));
                    add_post_meta($pid,'_bcss_season',(int)($m['season']??1));
                }
            }
        }
    }
    return true;
}

/** ------------------------------------------------------------------------
 * FRONTEND SHORTCODES
 * ------------------------------------------------------------------------- */
add_shortcode('beeclear_secret_santa',function($atts){
    if(!is_user_logged_in()) return '<div class="bcss-wrapper bcss-card"><p class="bcss-meta">'.esc_html__('Please log in to view your Gift Shuffle assignment.',BCSS_TD).'</p></div>';
    $o=bcss_get_options();
    if(!empty($o['load_default_styles'])){ wp_register_style('bcss-front',false,[],BCSS_VER); wp_add_inline_style('bcss-front',bcss_default_front_css()."\n".$o['custom_css']); wp_enqueue_style('bcss-front'); }
    elseif(!empty($o['custom_css'])){ wp_register_style('bcss-front-custom',false,[],BCSS_VER); wp_add_inline_style('bcss-front-custom',$o['custom_css']); wp_enqueue_style('bcss-front-custom'); }
    $labels=bcss_get_labels(); $u=wp_get_current_user(); $target=(int)get_user_meta($u->ID,'bcss_target_user',true);
    ob_start(); ?>
    <div class="bcss-wrapper"><div class="bcss-card">
        <h3 class="bcss-title"><?php echo esc_html__('My Gift Shuffle',BCSS_TD); ?></h3>
        <?php if($target): $t=get_user_by('id',$target); if($t): ?>
            <p class="bcss-meta"><?php echo sprintf(esc_html__('You will gift: %s',BCSS_TD),esc_html($t->display_name)); ?></p>
            <?php $hints=get_user_meta($t->ID,'bcss_message_to_santa',true); if(!empty($hints)): ?>
                <div class="bcss-hints"><?php echo wp_kses_post(wpautop($hints)); ?></div>
            <?php else: ?><p class="bcss-meta"><?php echo esc_html($labels['no_hints']); ?></p><?php endif; ?>
        <?php else: ?><p class="bcss-meta"><?php echo esc_html__('Assigned person not found.',BCSS_TD); ?></p><?php endif; else: ?>
            <p class="bcss-meta"><?php echo esc_html($labels['no_assignment']); ?></p>
        <?php endif; ?>
        <?php
        if(isset($_POST['bcss_front_save_message'])&&wp_verify_nonce($_POST['bcss_front_message_nonce']??'','bcss_front_message_action')){
            $msg=isset($_POST['bcss_message_to_santa'])?wp_kses_post(wp_unslash($_POST['bcss_message_to_santa'])):''; update_user_meta($u->ID,'bcss_message_to_santa',$msg);
            echo '<div class="bcss-meta">'.esc_html__('Message saved.',BCSS_TD).'</div>';
        }
        $my_msg=get_user_meta($u->ID,'bcss_message_to_santa',true);
        ?>
        <form class="bcss-form" method="post"><?php wp_nonce_field('bcss_front_message_action','bcss_front_message_nonce'); ?>
            <p><label for="bcss_message_to_santa"><?php echo esc_html($labels['your_hints_label']); ?></label></p>
            <p><textarea id="bcss_message_to_santa" name="bcss_message_to_santa" rows="5" style="width:100%;"><?php echo esc_textarea($my_msg); ?></textarea></p>
            <p><button type="submit" name="bcss_front_save_message" class="bcss-button"><?php echo esc_html($labels['save_message']); ?></button></p>
        </form>
    </div></div>
    <?php return ob_get_clean();
});

/** Affiliate shop shortcode (frontend) */
add_shortcode('bcss_gift_shop',function($atts){
    $o=bcss_get_options(); $labels=bcss_get_labels(); $aff=$o['affiliate'];
    if(empty($aff['enabled'])) return '';
    $disc=trim((string)$aff['disclaimer']); if($disc==='') $disc=(string)$labels['gift_shop_disclaimer'];
    $amz=bcss_aff_build_amazon_url('GIFTS'); $alg=bcss_aff_build_allegro_url('GIFTS');
    $css = '.bcss-shop-wrap{max-width:720px;margin:1.5rem auto}.bcss-shop-box{background:#fff;border:1px solid #eee;border-radius:8px;padding:1rem}.bcss-shop{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.bcss-shop input{min-width:260px}.bcss-shop small{display:block;color:#666;margin-top:8px}';
    wp_register_style('bcss-gift-shop',false,[],BCSS_VER); wp_add_inline_style('bcss-gift-shop',$css); wp_enqueue_style('bcss-gift-shop');
    ob_start(); ?>
    <div class="bcss-shop-wrap"><div class="bcss-shop-box">
        <h3><?php echo esc_html($labels['gift_shop_title']); ?></h3>
        <p class="bcss-meta"><?php echo esc_html($labels['gift_shop_hint']); ?></p>
        <div class="bcss-shop" data-amazon="<?php echo esc_attr($amz); ?>" data-allegro="<?php echo esc_attr($alg); ?>">
            <input type="text" id="bcss-gift-q-frontend" placeholder="<?php echo esc_attr($labels['gift_shop_placeholder']); ?>" />
            <?php if(!empty($aff['amazon']['enabled'])): ?><a class="button button-primary" id="bcss-gift-amazon-frontend" target="_blank" rel="sponsored nofollow noopener"><?php echo esc_html($labels['gift_shop_btn_amazon']); ?></a><?php endif; ?>
            <?php if(!empty($aff['allegro']['enabled'])): ?><a class="button" id="bcss-gift-allegro-frontend" target="_blank" rel="sponsored nofollow noopener"><?php echo esc_html($labels['gift_shop_btn_allegro']); ?></a><?php endif; ?>
        </div>
        <?php if($disc!=='') echo '<small>'.esc_html($disc).'</small>'; ?>
    </div></div>
    <script>(function(){var h=document.querySelector('.bcss-shop'); if(!h) return; var q=document.getElementById('bcss-gift-q-frontend'); var aA=document.getElementById('bcss-gift-amazon-frontend'); var aL=document.getElementById('bcss-gift-allegro-frontend'); function b(){var s=(q.value||'gift').trim(); if(aA){aA.href=h.getAttribute('data-amazon').replace('GIFTS',encodeURIComponent(s));} if(aL){aL.href=h.getAttribute('data-allegro').replace('GIFTS',encodeURIComponent(s));}} q.addEventListener('input',b); b();})();</script>
    <?php return ob_get_clean();
});

/** ------------------------------------------------------------------------
 * DEACTIVATION
 * ------------------------------------------------------------------------- */
register_deactivation_hook(__FILE__,function(){
    $o=bcss_get_options(); if(!empty($o['cleanup_on_deactivation'])) bcss_clear_all_data();
});
