<?php 
/**
 * WNote.php
 */

defined('IN_WITY') or die('Access denied');

/**
 * WNote manages all notes : stores, displays, ...
 * 
 * @package System\WCore
 * @author Johan Dufau <johan.dufau@creatiwity.net>
 * @version 0.4.0-09-06-2013
 */
class WNote {
	/**
	 * Note levels
	 */
	const ERROR   = 'error';
	const INFO    = 'info';
	const SUCCESS = 'success';
	
	/**
	 * @var array Notes to be displayed in a plain view
	 */
	private static $plain_stack = array();
	
	/**
	 * Raises an ERROR note
	 * 
	 * @param  string $code      note's code
	 * @param  string $message   note's message
	 * @param  string $handlers  handlers (several handlers separated by a comma can be specified)
	 * @return array array(level, code, message, handlers)
	 */
	public static function error($code, $message, $handlers = 'assign') {
		return self::raise(array(
			'level'    => self::ERROR,
			'code'     => $code, 
			'message'  => $message, 
			'handlers' => $handlers
		));
	}
	
	/**
	 * Raises an INFO note
	 * 
	 * @param  string $code      note's code
	 * @param  string $message   note's message
	 * @param  string $handlers  handlers (several handlers separated by a comma can be specified)
	 * @return array array(level, code, message, handlers)
	 */
	public static function info($code, $message, $handlers = 'assign') {
		return self::raise(array(
			'level'    => self::INFO,
			'code'     => $code, 
			'message'  => $message, 
			'handlers' => $handlers
		));
	}
	
	/**
	 * Raises a SUCCESS note
	 * 
	 * @param  string $code      note's code
	 * @param  string $message   note's message
	 * @param  string $handlers  handlers (several handlers separated by a comma can be specified)
	 * @return array array(level, code, message, handlers)
	 */
	public static function success($code, $message, $handlers = 'assign') {
		return self::raise(array(
			'level'    => self::SUCCESS,
			'code'     => $code, 
			'message'  => $message, 
			'handlers' => $handlers
		));
	}
	
	/**
	 * Raises a new note
	 * 
	 * @param  array $note The note as an array(level, code, message, handlers)
	 * @return array The same note given in argument
	 */
	public static function raise($note) {
		$handlers = explode(',', $note['handlers']);
		foreach ($handlers as $handler) {
			$handler = trim($handler);
			$function = 'handle_'.$handler;
			if (is_callable(array('WNote', $function))) {
				// Execute handler
				self::$function($note);
			} else {
				// If no handler was found, don't leave the screen blank
				$note = array(
					'level'   => self::ERROR,
					'code'    => 'note_handler_not_found',
					'message' => "WNote::raise() : Unfound handler <strong>\"".$handler."\"</strong><br /><u>Triggering note:</u>\n".self::handle_html($note)
				);
				self::handle_plain($note);
			}
		}
		
		return $note;
	}
	
	/**
	 * Ignores the note
	 * 
	 * @param array $note Note as returned by WNote::raise()
	 * @return void
	 */
	public static function handle_ignore(array $note) {
		// do nothing...
	}
	
	/**
	 * Returns an HTML form of the note
	 * 
	 * @param array $note Note as returned by WNote::raise()
	 * @return string HTML form of the note
	 */
	public static function handle_html(array $note) {
		return "<ul><li><strong>level:</strong> ".$note['level']."</li>\n"
			."<li><strong>code:</strong> ".$note['code']."</li>\n"
			."<li><strong>message:</strong> ".$note['message']."</li>\n"
			."</ul><br />\n";
	}
	
	/**
	 * Displays the note in an HTML format and then, kills the script
	 * 
	 * @param array $note Note as returned by WNote::raise()
	 */
	public static function handle_die(array $note) {
		static $died = false; // prevent from looping
		if (!$died) {
			$died = true;
			
			try {
				// Try to display the note an artistic way
				self::handle_plain($note);
				$plain_view = self::getPlainView();
				
				if (!is_null($plain_view)) {
					$response = new WResponse('_blank');
					if ($response->render($plain_view)) {
						die;
					}
				}
			} catch (Exception $e) {}
			
			// Default HTML display
			echo self::handle_html($note);
			die;
		}
	}
	
	/**
	 * Adds a note in the SESSION variable stack in order to display it when rendering the whole page
	 * 
	 * @param array $note Note as returned by WNote::raise()
	 * @see WNote::count()
	 * @see WNote::get()
	 */
	public static function handle_assign(array $note) {
		if (self::count($note['code']) == 0) {
			$_SESSION['notes'][$note['code']] = $note;
		}
	}
	
	/**
	 * Renders the note as the main application
	 * 
	 * @param array $note Note as returned by WNote::raise()
	 */
	public static function handle_display(array $note) {
		// Prepare a new view with the note
		$view = new WView();
		$view->assign('css', '/themes/system/note/note.css');
		$view->assign('notes_data', array($note));
		$view->setTemplate('themes/system/note/note_view.html');
		
		// Render the response
		$response = new WResponse('_blank');
		$response->render($view);
	}
	
	/**
	 * Handles note to be displayed in a plain HTML View.
	 * Oftenly used for failover purposes (a view did not manage to render since theme cannot be found for instance).
	 * 
	 * @param array $note Note as returned by WNote::raise()
	 */
	public static function handle_plain(array $note) {
		self::$plain_stack[] = $note;
	}
	
	/**
	 * Log handler
	 * Stores the note in a log file (system/wity.log)
	 * 
	 * @param array $note Note as returned by WNote::raise()
	 */
	public static function handle_log(array $note) {
		$file = fopen(SYS_DIR.'wity.log', 'a+');
		$text = sprintf("[%s] [%s] [user %s|%s] [route %s] %s - %s\r\n", 
			date('d/m/Y H:i:s', time()), 
			$note['level'], 
			@$_SESSION['userid'], 
			$_SERVER['REMOTE_ADDR'], 
			$_SERVER['REQUEST_URI'], 
			$note['code'], 
			$note['message']
		);
		fwrite($file, $text);
		fclose($file);
	}
	
	/**
	 * Email handler
	 * Sends the note by email to the administrator (defined in config/config.php)
	 * 
	 * @param array $note Note as returned by WNote::raise()
	 */
	public static function handle_email(array $note) {
		$email = WConfig::get('config.email');
		if (!empty($email)) {
			$mail = WHelper::load('phpmailer');
			$mail->CharSet = 'utf-8';
			$mail->From = $email;
			$mail->FromName = WConfig::get('config.site_name');
			$mail->AddAddress($email);
			$mail->Subject = "[".WConfig::get('config.site_name')."] ".$note['level']." note - ".$note['code'];
			$mail->Body = 
"<p>Dear developper,</p>
<p>A new <strong>".$note['level']."</strong> note was triggered:</p>
<ul>
	<li>Userid: ".@$_SESSION['userid']."</li>
	<li>Client ip: ".$_SERVER['REMOTE_ADDR']."</li>
	<li>Route: ".$_SERVER['REQUEST_URI']."</li>
	<li><strong>Code:</strong> ".$note['code']."</li>
	<li><strong>Message:</strong> ".$note['message']."</li>
</ul>
<p><em>WityNote</em></p>";
			$mail->IsHTML(true);
			$mail->Send();
			unset($mail);
		}
	}
	
	/**
	 * Debug handler
	 * If the debug mode is activated, email and log handlers will be trigered.
	 * 
	 * @param array $note Note as returned by WNote::raise()
	 */
	public static function handle_debug(array $note) {
		if (WConfig::get('config.debug') === true) {
			self::handle_log($note);
			self::handle_email($note);
		}
	}
	
	/**
	 * Returns the number of notes in the SESSION stack whose $code is matching the $pattern
	 * 
	 * @param string $pattern optional pattern to find a note by its code
	 * @return int number of notes whose $code is matching the $pattern
	 */
	public static function count($pattern = '*') {
		$count = 0;
		if (!empty($_SESSION['notes'])) {
			foreach ($_SESSION['notes'] as $code => $note) {
				if ($pattern == '*' || $code == $pattern || (strpos($pattern, '*') !== false && preg_match('#'.str_replace('*', '.*', $pattern).'#', $code))) {
					$count++;
				}
			}
		}
		return $count;
	}
	
	/**
	 * Returns and unset from the SESSION stack all notes whose $code is matching the $pattern
	 * 
	 * @param string $pattern optional pattern to find a note by its code
	 * @return array All notes having its $code matching the $pattern
	 */
	public static function get($pattern = '*') {
		$result = array();
		if (!empty($_SESSION['notes'])) {
			foreach ($_SESSION['notes'] as $code => $note) {
				if ($pattern == '*' || $code == $pattern || (strpos($pattern, '*') !== false && preg_match('#'.str_replace('*', '.*', $pattern).'#', $code))) {
					$result[] = $note;
					// remove the note
					unset($_SESSION['notes'][$code]);
				}
			}
		}
		return $result;
	}
	
	/**
	 * Parses a set of notes and returns the html response
	 * 
	 * @param array $notes Set of notes that will be parsed
	 * @return string The HTML response
	 */
	public static function getView(array $notes_data) {
		static $css_added = false;
		
		if (empty($notes_data)) {
			return new WView();
		}
		
		// Remove the notes from the stack
		foreach ($notes_data as $note) {
			unset($_SESSION['notes'][$note['code']]);
		}
		
		$view = new WView();
		$view->assign('css', '/themes/system/note/note.css');
		$view->assign('notes_data', $notes_data);
		$view->setTemplate('themes/system/note/note_view.html');
		
		return $view;
	}
	
	/**
	 * Prepares a view to display a set of notes in a fallback view
	 * 
	 * @return WView
	 */
	public static function getPlainView() {
		// Generate view
		if (!empty(self::$plain_stack)) {
			$notes_data = self::$plain_stack;
			self::$plain_stack = array();
			
			// Prepare a new view
			$view = new WView();
			$view->assign('css', '/themes/system/note/note.css');
			$view->assign('css', '/themes/system/note/note_plain.css');
			$view->assign('js', '/libraries/jquery-1.10.2/jquery-1.10.2.min.js');
			$view->assign('js', '/themes/system/note/note.js');
			$view->assign('notes_data', $notes_data);
			$view->setTemplate('themes/system/note/note_plain_view.html');
			
			return $view;
		}
		return null;
	}
}

?>
