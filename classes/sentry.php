<?php

/**
 * Part of the Sentry package for Fuel.
 *
 * @package    Sentry
 * @version    1.0
 * @author     Cartalyst LLC
 * @license    MIT License
 * @copyright  2011 Cartalyst LLC
 * @link       http://cartalyst.com
 */

namespace Sentry;

use Config;
use Cookie;
use FuelException;
use Session;
use Lang;

class SentryAuthException extends \FuelException {}
class SentryAuthConfigException extends \SentryAuthException {}
class SentryAuthUserNotActivatedException extends \SentryAuthException {}

/**
 * Sentry Auth class
 *
 * @package  Sentry
 * @author   Daniel Petrie
 */
class Sentry
{
	/**
	 * @var  string  Holds the column to use for login
	 */
	protected static $login_column = null;

	/**
	 * @var  Sentry_Attempts  Holds the Sentry_Attempts object
	 */
	protected static $attempts = null;

	/**
	 * @var  object  Caches the current logged in user object
	 */
	protected static $user = null;

	/**
	 * Prevent instantiation
	 */
	final private function __construct() {}

	/**
	 * Run when class is loaded
	 *
	 * @return  void
	 */
	public static function _init()
	{
		// load config
		Config::load('sentry', true);
		Lang::load('sentry', 'sentry');

		// set static vars for later use
		static::$login_column = trim(Config::get('sentry.login_column'));
		static::$attempts = new \Sentry_Attempts();

		// validate config settings

		// login_column check
		if (empty(static::$login_column))
		{
			throw new \SentryAuthConfigException(__('sentry.login_column_empty'));
		}

	}

	/**
	 * Get's either the currently logged in user or the specified user by id or Login
	 * Column value.
	 *
	 * @param   int|string  User id or Login Column value to find.
	 * @return  Sentry_User
	 */
	public static function user($id = null, $recache = false)
	{
		if ($id)
		{
			try
			{
				return new Sentry_User($id);
			}
			catch (SentryUserNotFoundException $e)
			{
				throw new \SentryAuthException($e->getMessage());
			}
		}
		// if session exists - default to user session
		else if(static::check())
		{
			if (static::$user and $recache == false)
			{
				return static::$user;
			}

			$user_id = Session::get(Config::get('sentry.session_var'));
			static::$user = new \Sentry_User($user_id);
			return static::$user;
		}

		// else return empty user
		return new Sentry_User();
	}

	/**
	 * Get's either the currently logged in user's group object or the
	 * specified group by id or name.
	 *
	 * @param   int|string  Group id or or name
	 * @return  Sentry_User
	 */
	public static function group($id = null)
	{
		if ($id)
		{
			return new \Sentry_Group($id);
		}

		return new Sentry_Group();
	}


	/**
	 * Attempt to log a user in.
	 *
	 * @param   string  Login column value
	 * @param   string  Password entered
	 * @param   bool    Whether to remember the user or not
	 * @return  bool
	 * @throws  SentryAuthException;
	 */
	public static function login($login_column_value, $password, $remember = false)
	{
		// log the user out if they hit the login page
		static::logout();

		// get login attempts
		$attempts = static::$attempts->get($login_column_value);

		// if attempts > limit - suspend the login/ip combo
		if ($attempts >= static::$attempts->get_limit())
		{
			try
			{
				static::$attempts->suspend($login_column_value);
			}
			catch(SentryUserSuspendedException $e)
			{
				throw new \SentryAuthException($e->getMessage());
			}
		}

		// make sure vars have values
		if (empty($login_column_value) or empty($password))
		{
			return false;
		}

		// if user is validated
		if ($user = static::validate_user($login_column_value, $password, 'password'))
		{
			// clear attempts for login since they got in
			static::$attempts->clear($login_column_value);

			// set update array
			$update = array();

			// if they wish to be remembers, set the cookie and get the hash
			if ($remember)
			{
				$update['remember_me'] = static::remember($login_column_value);
			}

			// if there is a password reset hash and user logs in - remove the password reset
			if ($user->get('password_reset_hash'))
			{
				$update['password_reset_hash'] = '';
				$update['temp_password'] = '';
			}

			$update['last_login'] = time();

			// update user
			if (count($update))
			{
				$user->update($update, false);
			}

			// set session vars
			Session::set(Config::get('sentry.session_var'), (int) $user->get('id'));

			return true;
		}

		return false;
	}

	/**
	 * Checks if the current user is logged in.
	 *
	 * @return  bool
	 */
	public static function check()
	{
		// get session
		$user_id = Session::get(Config::get('sentry.session_var'));

		// invalid session values - kill the user session
		if ($user_id === null or ! is_numeric($user_id))
		{
			// if they are not logged in - check for cookie and log them in
			if (static::is_remembered())
			{
				return true;
			}
			//else log out
			static::logout();

			return false;
		}

		return true;
	}

	/**
	 * Logs the current user out.  Also invalidates the Remember Me setting.
	 *
	 * @return  void
	 */
	public static function logout()
	{
		Cookie::delete(Config::get('sentry.remember_me.cookie_name'));
		Session::delete(Config::get('sentry.session_var'));
	}

	/**
	 * Activate a user account
	 *
	 * @param   string  Login Column value
	 * @param   string  User's activation code
	 * @return  bool
	 */
	public static function activate_user($login_column_value, $code)
	{
		$login_column_value = base64_decode($login_column_value);

		// make sure vars have values
		if (empty($login_column_value) or empty($code))
		{
			return false;
		}

		// if user is validated
		if ($user = static::validate_user($login_column_value, $code, 'activation_hash'))
		{
			// update pass to temp pass, reset temp pass and hash
			$user->update(array(
				'activation_hash' => '',
				'activated' => 1
			), false);

			return true;
		}

		return false;
	}

	/**
	 * Starts the reset password process.  Generates the necessary password
	 * reset hash and returns the new user array.  Password reset confirm
	 * still needs called.
	 *
	 * @param   string  Login Column value
	 * @param   string  User's new password
	 * @return  bool|array
	 */
	public static function reset_password($login_column_value, $password)
	{
		// make sure a user id is set
		if (empty($login_column_value) or empty($password))
		{
			return false;
		}

		// check if user exists
		$user = static::user($login_column_value);

		// create a hash for reset_password link
		$hash = \Str::random('alnum', 24);

		// set update values
		$update = array(
			'password_reset_hash' => $hash,
			'temp_password' => $password,
			'remember_me' => '',
		);

		// if database was updated return confirmation data
		if ($user->update($update))
		{
			$update = array(
				'login_column' => $login_column_value,
				'email' => $user->get('email'),
				'link' => base64_encode($login_column_value).'/'.$update['password_reset_hash']
			) + $update;

			return $update;
		}
		else
		{
			return false;
		}
	}

	/**
	 * Confirms a password reset code against the database.
	 *
	 * @param   string  Login Column value
	 * @param   string  Reset password code
	 * @return  bool
	 */
	public static function reset_password_confirm($login_column_value, $code)
	{
		$login_column_value = base64_decode($login_column_value);

		// get login attempts
		$attempts = static::$attempts->get($login_column_value);

		// if attempts > limit - suspend the login/ip combo
		if ($attempts >= static::$attempts->get_limit())
		{
			static::$attempts->suspend($login_column_value);
		}

		// make sure vars have values
		if (empty($login_column_value) or empty($code))
		{
			return false;
		}

		// if user is validated
		if ($user = static::validate_user($login_column_value, $code, 'password_reset_hash'))
		{
			// update pass to temp pass, reset temp pass and hash
			$user->update(array(
				'password' => $user->get('temp_password'),
				'password_reset_hash' => '',
				'temp_password' => '',
				'remember_me' => '',
			), false);

			return true;
		}

		return false;
	}

	/**
	 * Checks if a user exists by Login Column value
	 *
	 * @param   string  Login column value
	 * @return  bool|Sentry_User
	 */
	public static function user_exists($login_column_value)
	{
		try
		{
			$user = new Sentry_User($login_column_value);

			if ($user)
			{
				return true;
			}
			else
			{
				// this should never happen;
				return false;
			}
		}
		catch (SentryUserNotFoundException $e)
		{
			return false;
		}
	}

	/**
	 * Remember User Login
	 *
	 * @param int
	 */
	protected static function remember($login_column)
	{
		// generate random string for cookie password
		$cookie_pass = \Str::random('alnum', 24);

		// create and encode string
		$cookie_string = base64_encode($login_column.':'.$cookie_pass);

		// set cookie
		\Cookie::set(
			\Config::get('sentry.remember_me.cookie_name'),
			$cookie_string,
			\Config::get('sentry.remember_me.expire')
		);

		return $cookie_pass;
	}

	/**
	 * Check if remember me is set and valid
	 */
	protected static function is_remembered()
	{
		$encoded_val = \Cookie::get(\Config::get('sentry.remember_me.cookie_name'));

		if ($encoded_val)
		{
			$val = base64_decode($encoded_val);
			list($login_column, $hash) = explode(':', $val);

			// if user is validated
			if ($user = static::validate_user($login_column, $hash, 'remember_me'))
			{
				// update last login
				$user->update(array(
					'last_login' => time()
				));

				// set session vars
				Session::set(Config::get('sentry.session_var'), (int) $user->get('id'));

				return true;
			}
			else
			{
				static::logout();

				return false;
			}
		}

		return false;
	}

	/**
	 * Validates a Login and Password.  This takes a password type so it can be
	 * used to validate password reset hashes as well.
	 *
	 * @param   string  Login column value
	 * @param   string  Password to validate with
	 * @param   string  Field name (password type)
	 * @return  bool|Sentry_User
	 */
	protected static function validate_user($login_column_value, $password, $field)
	{
		// get user
		$user = static::user($login_column_value);

		// check activation status
		if ($user->activated != 1)
		{
			throw new \SentryAuthUserNotActivatedException('User has not activated their account.');
		}

		// check user status
		if ($user->status != 1)
		{
			throw new \SentryAuthException('This account has been disabled.');
		}

		// check password
		if ( ! $user->check_password($password, $field))
		{
			if ($field == 'password' or $field == 'password_reset_hash')
			{
				static::$attempts->add($login_column_value);
			}
			return false;
		}

		return $user;
	}

}
