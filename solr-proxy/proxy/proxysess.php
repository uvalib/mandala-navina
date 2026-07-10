<?php

/**
 * A helper script to return JSONP info about session or to terminate session with a JSONP message of success or not
 * To delete a session must either be called from same IP or include the pwd below as a param
 */

require_once 'check.php';
require_once '/usr/local/etc/settings/creds.php';
require_once '/usr/local/etc/settings/paths.php';

$myget = filter_input_array(INPUT_GET, FILTER_SANITIZE_STRING);
$action = !empty($myget['action']) ? htmlspecialchars($myget['action']) : 'info';
$wrapfunct = !empty($myget['json_wrf']) ? htmlspecialchars($myget['json_wrf']) : false;
$pwd = !empty($myget['pwd']) ? htmlspecialchars($myget['pwd']) : false;

// error_log("admin constant: " . $ADMIN_PW);
$adminpwd = htmlspecialchars($ADMIN_PW);

$status = false;
$msg = "No message";
$data = array();
$allSessions = [];
$protected_actions = ['list', 'delete', 'destroyall', 'info', 'serverinfo', 'test'];
$multisession_actions = ['list', 'destroyall'];

$sid = false;
$forbidden = false;

// If doing a protected action and password is wrong, then abort
if (in_array($action, $protected_actions)) {
    if (!$pwd || $pwd !== $adminpwd) {
        // error_log("Failed pwd: " . $pwd . ":" . $adminpwd);
        $msg = 'Forbidden! Password sent: ' . $pwd;
        $forbidden = true;
        $status = false;
    }
}

if (!$forbidden) {
    // error_log("Not forbidden");
    // If not in a multisession action, initial the session
    if (!in_array($action, $multisession_actions)) {
        // error_log("Not multisession");
        if (array_key_exists('sid', $myget)) {
            $sid = $myget['sid'];
            // error_log("sid is: $sid");
            unset($myget['sid']);
            session_id($sid);
        } else {
            error_log("No SID found");
        }
        session_start();
    } else {
        // error_log("Not starting session for multisession: $action");
    }

    switch($action) {
        case 'userid':
            $uid = 0;
            $msg = 'No user found.';
            if (isset($_SESSION['muid'])) {
                $uid = $_SESSION['muid'];
                $msg = "User found.";
                $status = true;
            }
            $data = array(
                "uid" => $uid,
                "sid" => session_id(),
            );
            break;

        case 'delete':
            if (!empty($_SESSION)) {
                // error_log("Destroying session $sid");
                session_unset();
                session_destroy();
                $msg = "Session $sid destroyed!";
                $status = true;
                // error_log("Message here is: $msg");
            } else {
                $msg = "\$_SESSION is empty for session $sid";
                $data = $_SESSION;
                $status = false;
                // error_log("Session, $sid, not found to delete! ($action)");
            }
            break;

        case 'destroyall':
            $sessionNames = scandir(session_save_path());
            foreach ($sessionNames as $sessionName) {
                $sessionName = str_replace("sess_", "", $sessionName);
                if (strpos($sessionName, ".") === false) { //This skips temp files that aren't sessions
                    session_id($sessionName);
                    session_start();
                    session_destroy();
                    $allSessions[] = $sessionName;
                }
            }
            $msg = "Destroyed all sessions!";
            $data = $allSessions;
            $status = true;
            break;

        case 'list':
            $sessionNames = scandir(session_save_path());
            foreach ($sessionNames as $sessionName) {
                $sessionName = str_replace("sess_", "", $sessionName);
                if (strpos($sessionName, ".") === false) { //This skips temp files that aren't sessions
                    session_id($sessionName);
                    session_start();
                    $allSessions[$sessionName] = $_SESSION;
                    session_abort();
                }
            }
            $msg = 'All sessions listed.';
            $status= true;
            $data = $allSessions;
            break;

        case 'info':
            $msg = 'Session info';
            $data = array_merge(array('sid' => session_id()), $_SESSION);
            if (!empty($data['last_ping'])) {
                $pingtime = '';
                try {
                    $date = new DateTime('now', new DateTimeZone('America/New_York'));
                    $date->setTimestamp($data['last_ping']);
                    $pingtime = $date->format('Y-m-d H:i:s');
                } catch(Exception $error) {
                    $pingtime = "There was a problem calculating the time";
                }
                $data['last_ping_time'] = $pingtime;
            }
            $status = true;
            break;

        case 'serverinfo':
            $msg = 'Server info';
            $data = $_SERVER;
            $status = true;
            break;

        case 'test':
            // place for testing code
            $data = file_get_contents(
                'https://mandala-texts-dev.internal.lib.virginia.edu/general/api/test/1',
                false,
                stream_context_create(
                    array('ssl' => array(
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                    )),
                ),
            );
            break;

        default:
            $msg = "Ping successful!";
            $status = true;
    }
}

// Prepare the payload
$data_out = json_encode($data);
$payload = array(
    "status" => $status,
    "message" => $msg,
    "data" => $data
);
$payload = json_encode($payload);
// error_log("sending payload");
// if $wrapfunct defined, return JSONP....
if ($wrapfunct) {
    header("ContentType: application/javascript");
    echo "$wrapfunct($payload)";
} else {
    // Otherwise return straight JSON
    header("ContentType: application/json");
    echo $payload;
}

