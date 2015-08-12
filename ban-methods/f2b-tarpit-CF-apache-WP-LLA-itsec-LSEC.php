<?php
/*
Plugin Name: Error.log 404
Plugin URI: http://www.online1.hu/
Description: Log 404 errors to Apache error.log as "File does not exist:"
Version: 2.4
Author: Szépe Viktor
Author URI: http://www.online1.hu/
*/

define('ERRORLOG_TARPIT', 8);
define('ERRORLOG_DEEP_TARPIT', 89);
define('ERRORLOG_LOGIN_BLOCK_SECS', 86400);
define('ERRORLOG_FAIL2BAN_BAN_LINES', 12);


// ------------------------------- METHODS -----------------------------------


// tarpit
function errorlog_action_tarpit($action) {
    switch ($action) {
        case 'ban':
            sleep(ERRORLOG_DEEP_TARPIT);
            return true;
            break;
        case 'score':
            sleep(ERRORLOG_TARPIT);
            return true;
            break;
    }
}


// CloudFlare client API
function errorlog_is_cf() {
    global $cf_api_host, $cf_api_port, $cloudflare_api_key, $cloudflare_api_email;
    // looking for cloudflare plugin
    if (!function_exists('load_cloudflare_keys')) return false;
    load_cloudflare_keys();
    return ($cloudflare_api_key && $cloudflare_api_email);
}

function errorlog_cf_send($action) {
    global $cf_api_host, $cf_api_port, $cloudflare_api_key, $cloudflare_api_email;
    if (!errorlog_is_cf()) return 'not-cf';
    $cf_url = str_replace('ssl', 'https', $cf_api_host) . ':' . $cf_api_port . '/api_json.html';
    $postdata = array('a'        => $action,  // 'w'hite'l'ist, 'ban', 'nul'
                      'tkn'      => $cloudflare_api_key,
                      'email'    => $cloudflare_api_email,
                      'key'      => $_SERVER['HTTP_CF_CONNECTING_IP']  // proxy?
                );
    $cf_res = wp_remote_post($cf_url, array(
        'method' => 'POST',
        'blocking' => true,
        'body' => $postdata,
        )
    );
    if ( is_wp_error($cf_res) ) return 'cf-http-error:'.serialize($cf_res);
    $cf_res_body = json_decode($cf_res['body']);
    if ( !$cf_res_body ) return 'cf-response-body-notfound:'.serialize($cf_res);
    if ($cf_res_body->result == 'success') {
        return true;
    } else {
        return 'cf-comm-failure:'.serialize($cf_res_body);
    }
}

function errorlog_action_cf($action) {
    switch ($action) {
        case 'ban':
            return errorlog_cf_send('ban');
            break;
        case 'unban':
            return errorlog_cf_send('nul');
            break;
        case 'score':
            // FIXME report to CF!!
            return true;
            break;
    }
}


// WordPress - exit; early
function errorlog_action_wordpress($action) {
    //ban flush buffers; exit;
    //score sleep(ERRORLOG_TARPIT); ???
    return true;
}


// Limit Login Attempt plugin
function errorlog_is_limitlogin() {
    return defined(LIMIT_LOGIN_LOCKOUT_NOTIFY_ALLOWED);
}

function errorlog_action_limitlogin($action) {
    if (!errorlog_is_limitlogin()) return 'limitlogin-not-installed';
    switch ($action) {
        case 'ban':
            $lockouts = get_option('limit_login_lockouts');
            if (!is_array($lockouts)) return 'limitlogin-not-array';
            $lockouts[$_SERVER['REMOTE_ADDR']] = time() + ERRORLOG_LOGIN_BLOCK_SECS;
            return update_option('limit_login_lockouts', $lockouts);
            break;
        case 'score':
            sleep(ERRORLOG_TARPIT); // FIXME add one limitlogin attempt
            return true;
            break;
    }
}

// --------------- DO ACTION ---------------------
function errorlog_do_action($method , $action, $score = 0) {
    $result = false;
    switch ($method) {
        case 'tarpit':
            $result = errorlog_action_tarpit($action);
            break;
        case 'cf':
            // is_cf
            $result = errorlog_action_cf($action);
            break;
        case 'apache':
            // is_apache
            $result = errorlog_action_apache($action);
            break;
        case 'wordpress':
            $result = errorlog_action_wordpress($action);
            break;
        case 'limitlogin':
            // is_ll
            $result = errorlog_action_limitlogin($action);
            break;
    }
    //+isnumeric($result) -> add score;
    //score system: here ?? / in methods ??
    //+admin: max score per IP per timeframe;
    //+send email report if ($method='ban') {$action
    $trace=debug_backtrace();error_log('Error.log 404 method='.$method.' detection='.$trace[1]['function'].'---'.$action.'/'.$score);
    if ($result !== true && $action == 'ban') {
        header('HTTP/1.0 403 Forbidden');
        exit;
    }
}


//  ------------------------------ MAIN ------------------------------------



// Limit Login Attempt lockout
function errorlog_login_failed($username) {
    global $limit_login_just_lockedout;
    if ($limit_login_just_lockedout) errorlog_do_action(ERRORLOG_METHOD, 'ban');
}
/* //  attempts AFTER limit-login blocked user and this plugin blocked IP
function errorlog_authenticate_user($userdata, $password) {
    if (is_wp_error($userdata) && !empty($userdata->errors['too_many_retries'])) {
        error_log('do_action');
    }
    return $userdata;
}
add_filter('wp_authenticate_user', 'errorlog_authenticate_user', 100000, 2);*/
