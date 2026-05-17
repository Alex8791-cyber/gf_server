<?php

declare(strict_types=1);

namespace gfserver\sso\auth\provider;

use phpbb\auth\provider\base;
use phpbb\config\config;
use phpbb\db\driver\driver_interface;
use phpbb\user;

/**
 * phpBB auth provider: authenticates against the gf_server game accounts and
 * auto-provisions the phpBB user on first login. The credential check is
 * delegated to the portal's already-tested GfServer\AccountService, loaded
 * from the portal install on the same server.
 */
class gfserver extends base
{
    /** Portal install root — fixed by deploy/install.sh (Block 1). */
    private const PORTAL_AUTOLOAD = '/opt/gfserver/web/vendor/autoload.php';

    /** @var config */
    protected $config;

    /** @var driver_interface */
    protected $db;

    /** @var user */
    protected $user;

    /** @var string */
    protected $phpbb_root_path;

    /** @var string */
    protected $php_ext;

    public function __construct(config $config, driver_interface $db, user $user, $phpbb_root_path, $php_ext)
    {
        $this->config = $config;
        $this->db = $db;
        $this->user = $user;
        $this->phpbb_root_path = $phpbb_root_path;
        $this->php_ext = $php_ext;
    }

    /**
     * Credential-based provider — there is no ambient session identity to
     * verify at init time.
     */
    public function init()
    {
        return false;
    }

    /**
     * Authenticate $username/$password against the game accounts.
     *
     * @param string $username
     * @param string $password
     * @return array{status: int, error_msg: string|false, user_row: array}
     */
    public function login($username, $password)
    {
        if (!$username)
        {
            return array(
                'status'    => LOGIN_ERROR_USERNAME,
                'error_msg' => 'LOGIN_ERROR_USERNAME',
                'user_row'  => array('user_id' => ANONYMOUS),
            );
        }

        if (!$password)
        {
            return array(
                'status'    => LOGIN_ERROR_PASSWORD,
                'error_msg' => 'NO_PASSWORD_SUPPLIED',
                'user_row'  => array('user_id' => ANONYMOUS),
            );
        }

        $account_id = $this->game_account_id((string) $username, (string) $password);
        if ($account_id === null)
        {
            return array(
                'status'    => LOGIN_ERROR_PASSWORD,
                'error_msg' => 'LOGIN_ERROR_PASSWORD',
                'user_row'  => array('user_id' => ANONYMOUS),
            );
        }

        $row = $this->phpbb_user_row((string) $username);
        if ($row)
        {
            return array(
                'status'    => LOGIN_SUCCESS,
                'error_msg' => false,
                'user_row'  => $row,
            );
        }

        // First forum login for this game account — create the phpBB user.
        if (!function_exists('user_add'))
        {
            include $this->phpbb_root_path . 'includes/functions_user.' . $this->php_ext;
        }
        user_add($this->new_user_row((string) $username, $account_id));

        return array(
            'status'    => LOGIN_SUCCESS,
            'error_msg' => false,
            'user_row'  => $this->phpbb_user_row((string) $username),
        );
    }

    /**
     * Validate credentials via the portal's AccountService.
     * Returns the accounts.id on success, or null on failure.
     */
    private function game_account_id(string $username, string $password): ?int
    {
        $accounts = $this->account_service();

        return $accounts->authenticate($username, $password);
    }

    /** Build a GfServer\AccountService bound to the game databases. */
    private function account_service(): \GfServer\AccountService
    {
        require_once self::PORTAL_AUTOLOAD;

        return new \GfServer\AccountService(
            new \GfServer\Database(\GfServer\Config::fromEnv())
        );
    }

    /** Fetch the phpbb_users row for $username, or false if there is none. */
    private function phpbb_user_row(string $username)
    {
        $sql = 'SELECT *
            FROM ' . USERS_TABLE . "
            WHERE username_clean = '" . $this->db->sql_escape(utf8_clean_string($username)) . "'";
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $row;
    }

    /**
     * Build the phpbb_users row for a newly auto-provisioned account.
     *
     * @return array<string, mixed>
     */
    private function new_user_row(string $username, int $accountId): array
    {
        $sql = 'SELECT group_id
            FROM ' . GROUPS_TABLE . "
            WHERE group_name = 'REGISTERED'
                AND group_type = " . GROUP_SPECIAL;
        $result = $this->db->sql_query($sql);
        $group_row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (!$group_row)
        {
            trigger_error('NO_GROUP');
        }

        // Only adopt the portal email once it is verified — an unverified
        // address may belong to someone else.
        $web = $this->account_service()->webAccount($accountId);
        $email = ($web !== null && $web['email_verified'] && $web['email'] !== null && $web['email'] !== '')
            ? $web['email']
            : $username . '@accounts.invalid';

        return array(
            'username'      => $username,
            'user_password' => '',
            'user_email'    => $email,
            'group_id'      => (int) $group_row['group_id'],
            'user_type'     => USER_NORMAL,
            'user_ip'       => $this->user->ip,
            'user_new'      => $this->config['new_member_post_limit'] ? 1 : 0,
        );
    }
}
