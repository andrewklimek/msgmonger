<?php
namespace msgmonger;
/*
Plugin Name: Msg Monger
Plugin URI:  https://github.com/andrewklimek/msgmonger
Description: a messaging system for registered users of your WordPress site
Version:     1.0.0
Author:      Andrew J Klimek
Author URI:  https://github.com/andrewklimek
License:     GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Msg Monger is free software: you can redistribute it and/or modify 
it under the terms of the GNU General Public License as published by the Free 
Software Foundation, either version 2 of the License, or any later version.

Msg Monger is distributed in the hope that it will be useful, but WITHOUT 
ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A 
PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with 
Msg Monger. If not, see https://www.gnu.org/licenses/gpl-2.0.html.
*/

//TODO error return

if(!function_exists('poo')){function poo($v,$l=''){if(WP_DEBUG_LOG){error_log("***$l***\n".var_export($v,true));}}}

register_activation_hook( __FILE__, __NAMESPACE__.'\activation' );
add_shortcode( 'msgmonger_form', __NAMESPACE__.'\messages_form_shortcode' );
add_shortcode( 'msgmonger_threads', __NAMESPACE__.'\threads_shortcode' );
add_shortcode( 'msgmonger_messages', __NAMESPACE__.'\messages_shortcode' );
add_action( 'wp_enqueue_scripts', function() { wp_register_style( 'msgmonger-style', plugin_dir_url( __FILE__ ) . 'style.css' ); } );


function send_message( $to_id = null ) {
	
	if ( empty( $_REQUEST['msgid'] ) && ! $to_id ) {
		return false;// won't be able to send anything.
	}
	
	// This coudl run twice on a page depending on the use of shortcodes. let's prevent that.
	// if ( false === wp_cache_add( $key, $data ) ) return;
	
	if ( empty( $_POST['msgmonger']['nonce'] ) ) {
		return false;
	}
	
	$user = wp_get_current_user();
	$sum = (int) $user->ID + (int) $_POST['msgmonger']['nonce'];

	if ( ! get_transient( "msgmonger_nonce_{$sum}" ) ) {
		poo("No transient for $sum");
		return false;
	}
	
	
	if ( ! empty( $_REQUEST['msgmonger']['message'] ) ) {
		$message = wpautop( wp_kses_post( stripslashes( $_REQUEST['msgmonger']['message'] ) ) );
	} else {
		echo "No message content";
		return false;
	}
	
	global $wpdb;
	
	// Check for message ID
	if ( empty( $_REQUEST['msgid'] ) ) {// no msgid, make a new thread.
		
		if ( ! $to_id ) {// this should not be possible... remove at some point
			echo "Something's wrong.  No to_id found.";
			return false;
		}
		// concatenate both user IDs, lowest first
		$msgid = $user->ID < $to_id ? $user->ID . $to_id : $to_id . $user->ID;
		// make 8 char hash from user IDs
		$msgid = hash( 'crc32b', $msgid );
		// add another 8 characters to the hash, what the heck
		$msgid .= hash( 'adler32', $msgid );

		$wpdb->insert(
		"{$wpdb->prefix}msgmonger_threads",
		array(
			"msgid"		=>	$msgid,
			"init_from"	=>	$user->ID,
			"init_to"	=>	$to_id
		),
		array( '%s', '%d', '%d' )
	);
	// new row number form insert
	// $row_number = $wpdb->insert_id;
	} else {
		$msgid = $_REQUEST['msgid'];
		$thread = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}msgmonger_threads WHERE msgid=%s", $msgid ) );
		$to_id = $thread->init_from == $user->ID ? $thread->init_to : $thread->init_from;
	}
	//
	// New Message
	//

	// mysql2date( get_option( 'date_format' ), $post->post_date );
	// $date_display = current_time( get_option( 'date_format' ) );
	$date_gmt_sql = current_time( 'mysql', 1 );

	$wpdb->insert(
	"{$wpdb->prefix}msgmonger_messages",
	array(
		"msgid"		=>	$msgid,
		"msg_from"	=>	$user->ID,
		"content"	=>	$message,
		"msg_date"	=>	$date_gmt_sql
	),
	array( '%s', '%d', '%s', '%s' )
	);

	// send Mail
	$admin_email = get_option('admin_email');
	$site_name = str_replace( '&#039;', "'", get_option('blogname') );// something odd about apostrophes
	$to_obj = get_user_by( 'id', $to_id );// TODO parse to accept email, login, and id
	$to = $to_obj->user_email;
	$subject = "New message from {$user->first_name}";
	$headers[] = "From: $site_name <{$admin_email}>";
	// $headers[] = "Bcc: andrew.klimek@gmail.com";// for testing
	$headers[] = "Content-Type: text/html; charset=UTF-8";
	$link = get_option( 'siteurl' ) ."/login/";// get_option( 'siteurl' ) ."/my-account/?msgid={$msgid}#inbox-messages";
	
	$formatted_local_time = current_time( get_option( 'date_format' ) .' '. get_option( 'time_format' ) );
	
	$body = "<p>Hi {$to_obj->first_name},</p>

	<p>You've received a new message!
	<br>Message:</p>

	{$message}
	
	<hr>
	<p>Please be sure to respond to the message. <a href='{$link}'>Login</a> to your account to reply.</p>

	<p>Kind regards,
	<br>The kindredspiritshouse.com team</p>";

	wp_mail( $to, $subject, $body, $headers );
	
	$admin_body = "<table>
	<tr><th>From</th><td>{$user->display_name}</td></tr>
	<tr><td>Email</td><td>{$user->user_email}</td></tr>
	<tr><td>User ID</td><td>{$user->ID}</td></tr>
	<tr><td>Role</td><td>{$user->roles[0]}</td></tr>
	<tr><th>To</th><td>{$to_obj->display_name}</td></tr>
	<tr><td>Email</td><td>{$to_obj->user_email}</td></tr>
	<tr><td>User ID</td><td>{$to_obj->ID}</td></tr>
	<tr><td>Role</td><td>{$to_obj->roles[0]}</td></tr>
	<tr><th>Time</th><td>{$formatted_local_time}</td></tr>
	<tr><th>Message</th><td>{$message}</td></tr></table>";
	
	wp_mail( $admin_email, "New message sent", $admin_body, $headers );
	

	delete_transient( "msgmonger_nonce_{$sum}" );
	return $msgid;
}

function message_form( $msgid = '' ) {
	$nonce = mt_rand();
	$user = wp_get_current_user();
	$sum = $nonce + (int) $user->ID;
	set_transient( "msgmonger_nonce_{$sum}", "OK", 30000 );
	
	wp_enqueue_style( 'msgmonger-style' );
	
	ob_start();?>
	<form class="message-submission-form" action="" method="post">
		<input type="hidden" name="msgmonger[nonce]" value="<?php echo $nonce ?>">
		<!-- <input type="hidden" name="msgmonger[path]" value="<?php echo esc_attr( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) ) ?>" /> -->
		<?php // wp_referer_field();?>
		<input type="hidden" name="msgmonger[msgid]" value="<?php echo $msgid ?>">
		<div class="message-type-send">
			<textarea name="msgmonger[message]" required placeholder="Type a message..."></textarea>
			<input type="submit" value="Send">
		</div>
	</form>
	<?php
	return ob_get_clean();
}

function messages_form_shortcode( $atts ) {
	// Logged in?
	if ( ! is_user_logged_in() ) {
		return sprintf( '<a href="%1s">%2s</a>', esc_url( wp_login_url() ), 'Click Here To Login' );
	}
	
	//		$atts = shortcode_atts( array(
	// 		'to' => null,
	// 		// 'message' => 'Message',
	// 		// 'send' => 'Send'
	// 	), $atts, 'msgmonger_form' );
	
	if ( empty( $atts['to'] ) || ! is_numeric( $atts['to'] ) ) {
		return 'Please put a user ID number in the "to" parameter of the shortcode.';
	}
	
	send_message( $atts['to'] );

	return message_form();

}

function messages_shortcode( $atts ) {
	
	if ( ! is_user_logged_in() ) return sprintf( '<a href="%1s">%2s</a>', esc_url( wp_login_url() ), 'Click Here To Login' );
	
	$msgid = send_message();	
	 
	if ( ! $msgid ) {
		if ( ! empty( $_REQUEST['msgid'] ) ) {
			$msgid = $_REQUEST['msgid'];
		} else {
			return "";
		}
	}
	
	global $wpdb;
	$user = wp_get_current_user();// current user object
	$with = null;// user object person this conversation is with
	// $date_format = get_option( 'time_format' ) .' '. get_option( 'date_format' );// probably slow to get the format in the for loop
	$out = "";
	
	// Check permission
	$allowed = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}msgmonger_threads WHERE msgid=%s AND ( init_from=%d OR init_to=%d )", $msgid, $user->ID, $user->ID ) );
	if ( ! $allowed ) {
		return "That message cannot be found.";
	}

	// FROM {$wpdb->prefix}msgmonger_threads t JOIN {$wpdb->prefix}msgmonger_messages m USING (msgid)	
	$messages = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}msgmonger_messages WHERE msgid=%s ORDER BY msg_date DESC", $msgid ) );

	$out .= message_form( $msgid );

	$out .= "<div class='msgmonger-messages-list'>";
	foreach ( $messages as $message ) {
		// $msg_date = date( $date_format, strtotime( get_date_from_gmt( $message->msg_date ) ) );
		$msg_date = human_time_diff( strtotime( get_date_from_gmt( $message->msg_date ) ), current_time( 'timestamp' ) );
		
		if ( $message->msg_from == $user->ID ) {
			$class = 'me';
			$name = $user->first_name;
		} else {
			$class = 'them';
			if ( ! $with ) {
				$with = get_user_by( 'id', $message->msg_from );
			}
			$name = $with->first_name;
			
			// $oldest_unread_marked = false;// disable for now cause we're trying new to old
			if ( ! $message->msg_read ) {
				$class .= " message_unread";
				// if ( ! $oldest_unread_marked ) {
				// 	$class .= " oldest_unread";
				// 	$oldest_unread_marked = true;
				// }
			}
		}
		$out .= "<div class='message_from_{$class}'><div class='message-meta'><span class='author'>{$name}</span> <time>{$msg_date}</time></div><p>{$message->content}</p></div>";
	}
	$out .= "</div>";
	
	// mark all as read
	if ( $with ) {// don't bother is there's no message from the other person
		$wpdb->update( "{$wpdb->prefix}msgmonger_messages", 
			array( 'msg_read' => 1 ), 
			array( 'msgid' => $msgid, 'msg_read' => 0, 'msg_from' => $with->ID ), 
			array( '%s', '%d', '%d' ), 
			array( '%d' ) 
		);
	}
	
	wp_enqueue_style( 'msgmonger-style' );
	
	return $out;
}

function threads_shortcode( $atts ) {
	if ( ! $user_id = get_current_user_id() ) return sprintf( '<a href="%1s">%2s</a>', esc_url( wp_login_url() ), 'Click Here To Login' );
	// 	ORDER BY msg_date DESC
	// FROM {$wpdb->prefix}msgmonger_threads t JOIN {$wpdb->prefix}msgmonger_messages m USING (msgid)
	
	global $wpdb;
	$threads = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}msgmonger_threads WHERE ( init_from=%d OR init_to=%d )", $user_id, $user_id ) );
	
	if ( ! $threads ) {
		return "<p>You haven't started any conversations yet.</p>";
	}

	$msgid = ! empty( $_REQUEST['msgid'] ) ? $_REQUEST['msgid'] : '';// to mark the current thread
	
	$snippet_chars = isset( $atts['snippet'] ) ? $atts['snippet'] : 80;// default snippet characters
	
	$out = "";
	
	
	if ( $msgid ) {// Kindred Specific
		$out .= '<a href=".#inbox-messages">&larr; Back to messages</a>';
		$out .= '<style>.msgmonger-thread:not(.current_message){display:none;}</style>';
	}
	
	// $date_format = get_option( 'time_format' ) .' '. get_option( 'date_format' );// probably slow to get the format in the for loop
	
	$out .= "<div class='msgmonger-threads-list'>";
	$thread_html = array();

	foreach ( $threads as $thread ) {
		$with = $thread->init_from == $user_id ? $thread->init_to : $thread->init_from;
		$with_obj = get_user_by( 'id', $with );
		$class = $msgid === $thread->msgid ? " current_message" : "";
		
		// Get User Data from Formidable - Kindred Specific
		$user_info_view = '';
		if ( $with_obj->has_cap('sitter') ) {
			$user_info_view = \FrmProDisplaysController::get_shortcode( array( 'id' => 'conversation-info-sitter', 'user_id' => $with ) );
		} elseif ( $with_obj->has_cap('need-sitter') ) {
			$user_info_view = \FrmProDisplaysController::get_shortcode( array( 'id' => 'conversation-info-need-sitter', 'user_id' => $with ) );
		} elseif ( $with_obj->has_cap('house-swapper') ) {
			$user_info_view = \FrmProDisplaysController::get_shortcode( array( 'id' => 'conversation-info-swap', 'user_id' => $with ) );
		}
		
		$unread = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}msgmonger_messages WHERE msgid=%s AND msg_read=0 AND msg_from=%d ORDER BY msg_date DESC", $thread->msgid, $with ) );
		
		$snippet = '';
		if ( $snippet_chars ) {
			$message = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}msgmonger_messages WHERE msgid=%s ORDER BY id DESC LIMIT 1", $thread->msgid ) );
			
			$snippet = str_replace(array("\r", "\n"), ' ', strip_tags( $message->content ) );// clean up
			if ( strlen( $snippet ) > $snippet_chars ) {
				$snippet = substr( $snippet, 0, strpos( $snippet, ' ', $snippet_chars ) );
			}
			$snippet = "<p class='msgmonger-snippet'>{$snippet}</p>";
		}
		
		if ( $unread === "0" ) {
			$unread = "";
		} else {
			$unread = " ({$unread} unread)";
		}
		
		$timestamp = strtotime( get_date_from_gmt( $message->msg_date ) );
		// $msg_date = date( $date_format, strtotime( $timestamp );
		$msg_date = human_time_diff( $timestamp, current_time( 'timestamp' ) );
		
		$thread_html[ $timestamp ] = "		<div class='msgmonger-thread{$class}'>
			$user_info_view
			<a href='/my-account/?msgid={$thread->msgid}#inbox-messages'>
				<h2>Conversation with {$with_obj->first_name}{$unread}</h2>
				<time>$msg_date</time>
				$snippet
			</a>
		</div>";// $user_info_view is Kindred Specific
	}
	krsort($thread_html);// order by date of latest message, newest first
	$out .= implode( $thread_html );
	$out .= "</div>";
	
	wp_enqueue_style( 'msgmonger-style' );
	
	return $out;
}

function create_database() {
	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );// to use dbDelta()
	global $wpdb;

	$charset_collate = $wpdb->get_charset_collate();

	dbDelta( "CREATE TABLE {$wpdb->prefix}msgmonger_threads (
	id bigint(20) unsigned NOT NULL auto_increment,
	msgid char(16) NOT NULL,
	init_from bigint(20) unsigned NOT NULL default 0,
	init_to bigint(20) unsigned NOT NULL default 0,
	PRIMARY KEY  (id),
	KEY msgid (msgid)
	) ENGINE=InnoDB $charset_collate;" );

	dbDelta( "CREATE TABLE {$wpdb->prefix}msgmonger_messages (
	id bigint(20) unsigned NOT NULL auto_increment,
	msgid char(16) NOT NULL,
	msg_from bigint(20) unsigned NOT NULL default 0,
	msg_read tinyint(1) NOT NULL default 0,
	msg_date datetime NOT NULL default '0000-00-00 00:00:00',
	content longtext NOT NULL,
	PRIMARY KEY  (id)
	) ENGINE=InnoDB $charset_collate;" );

	// update_option( 'msgmonger_messages_db_version', '1.0' );// Why not worry about version number when we update and just check for ANY version number?
}

function activation() {
	create_database();
}

function import_messages() {
	
	$csv = array_map( 'str_getcsv', file( __DIR__.'/data.csv', FILE_SKIP_EMPTY_LINES ) );
	
	// remove header row
	array_shift( $csv );

	foreach ( $csv as $row ) {
		// $row = $csv[0];// for testing first row only.  disable foreach

		global $wpdb;

		$message = '<p>' . str_replace( '\\n', '</p><p>', $row[0] ) . '</p>';

		$to_obj = get_user_by( 'email', $row[1] );
		$user = get_user_by( 'id', $row[3] );

		if ( $to_obj  === false || $user === false ) {
			poo( $row, 'this row had a missing user' );
			continue;
		}

		$local_timestamp = $row[4];
		$date_gmt_sql = get_gmt_from_date( date( 'Y-m-d H:i:s', strtotime( $local_timestamp ) ) );

		// make message ID

		// concatenate both user IDs, lowest first
		$msgid = $user->ID < $to_obj->ID ? $user->ID . $to_obj->ID : $to_obj->ID . $user->ID;
		// make 8 char hash from user IDs
		$msgid = hash( 'crc32b', $msgid );
		// add another 8 characters to the hash, what the heck
		$msgid .= hash( 'adler32', $msgid );

		// check for that msgid
		$thread = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}msgmonger_threads WHERE msgid=%s", $msgid ) );

		// if it doens't exist, create it
		if ( $thread === null ) {

			$wpdb->insert(
			"{$wpdb->prefix}msgmonger_threads",
				array(
					"msgid"		=>	$msgid,
					"init_from"	=>	$user->ID,
					"init_to"	=>	$to_obj->ID
				),
				array( '%s', '%d', '%d' )
			);
		}

		// Make the New Message

		$wpdb->insert(
		"{$wpdb->prefix}msgmonger_messages",
			array(
				"msgid"		=>	$msgid,
				"msg_from"	=>	$user->ID,
				"content"	=>	$message,
				"msg_date"	=>	$date_gmt_sql,
				"msg_read"	=>	1,
			),
			array( '%s', '%d', '%s', '%s' )
		);
	}
}