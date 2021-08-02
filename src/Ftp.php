<?php
namespace AppZz\Filesystem;
use AppZz\Helpers\Arr;
use Exception;

/**
 * @package    Filesystem/Ftp
 * @category   Network
 * @author     CoolSwitcher
 * @copyright  (c) 2018 AppZz
 * @license    MIT
 */
class Ftp {

    // Config
    public $config = array();

    // Connection id
    protected $conn_id = NULL;

    // Connection status
    public $connected = FALSE;

    /**
     * Constructor
     *
     * Detect if the FTP extension loaded
     *
     */
    public function __construct (array $config = [])
    {
        if ( ! extension_loaded('ftp') )
        {
            throw new Exception("PHP extension FTP is not loaded.");
        }

        $this->config = $config;
    }

    /**
     * Get the Kohana_Ftp.
     * $config = FTP::factory();
     *
     * @param string $config
     * @return Ftp
     * @throws Exception
     */
    public static function factory (array $config = [])
    {
       return new Ftp ($config);
    }

    /**
     * Magic config
     *
     *     FTP::factory()->
     *          host('ftp://site.com')->
     *          user('my-user')->
     *      password('my-pass')->
     *          list_files();
     * @param $name
     * @param array $args
     * @return $this
     */
    public function __call($name, $args = array())
    {
        $pattern = '/^(host|user|password|port|passive)$/i';
        if ( isset($args[0]) && is_array( $args[0] ) )
        {
            foreach ($args[0] as $key => $value)
            {
                if (preg_match($pattern, $key))
                {
                    $this->config[$key] = $value;
                    return $this;
                }
            }
        }
        if ( preg_match($pattern, $name) )
        {
            $this->config[$name] = $args[0];
            return $this;
        }
        if ( preg_match('/^timeout$/', $name) )
        {
            $this->config['timeout'] = $args[0];
            return $this;
        }
    }

    /**
     * FTP Connect
     *
     * @return bool
     * @throws Exception
     */
    public function connect()
    {
        if ( TRUE === $this->connected )
        {
            return TRUE;
        }

        if ( empty( $this->config ) )
        {
            throw new Exception('FTP config not set.');
        }

        $this->config['port'] = ( isset( $this->config['port'] ) ) ? $this->config['port'] : 21;

        if ( ! isset( $this->config['host'] ) )
        {
            throw new Exception('FTP host not set.');
        }

        $this->config["ssh"] = ( isset( $this->config["ssh"] ) ) ? $this->config["ssh"] : FALSE;
        $this->config["timeout"] = ( isset( $this->config["time"] ) ) ? $this->config["timeout"] : 90;

        if ( TRUE === $this->config["ssh"] && FALSE === ($this->conn_id = @ftp_ssl_connect($this->config['host'], $this->config['port'], $this->config["timeout"])))
        {
            throw new Exception('FTP unable to ssh connect');
        } else if (FALSE === ($this->conn_id = @ftp_connect($this->config['host'], $this->config['port'], $this->config["timeout"])))
        {
            throw new Exception('FTP unable to connect');
        }

        if ( ! $this->_login())
        {
            throw new Exception('FTP unable to login');
            return FALSE;
        }

        // Set passive mode if needed
        if ( ! isset( $this->config['passive'] ) || $this->config['passive'] === TRUE )
        {
            ftp_pasv($this->conn_id, TRUE);
        }

        if ( isset( $this->config['force_utf8'] ) AND $this->config['force_utf8'] === TRUE )
        {
            ftp_raw($this->conn_id, 'OPTS UTF8 ON');
        }

        return $this->connected = TRUE;
    }

    /**
     * FTP Login
     *
     * @access  private
     * @return  bool
     */
    private function _login()
    {
        $this->config['user'] = ( $this->config['user'] ) ? $this->config['user'] : NULL;
        $this->config['password'] = ( $this->config['password'] ) ? $this->config['password'] : NULL;
        return @ftp_login($this->conn_id, $this->config['user'], $this->config['password']);
    }

    /**
     * Validates the connection ID
     *
     * @return bool
     * @throws Exception
     */
    private function _is_conn()
    {
        $this->connect();

        if ( ! is_resource($this->conn_id) )
        {
            throw new Exception('FTP no connection');
        }

        return TRUE;
    }

    /**
     * Change directory
     * The second parameter lets us momentarily turn off debugging so that
     * this function can be used to test for the existence of a folder
     * without throwing an error.  There's no FTP equivalent to is_dir()
     * so we do it by trying to change to a particular directory.
     * Internally, this parameter is only used by the "mirror" function below.
     *
     * @param string $path
     * @param bool $supress_debug
     * @return bool
     * @throws Exception
     */
    public function changedir($path = '', $supress_debug = FALSE)
    {
        if ($path === '' OR ! $this->_is_conn())
        {
            return FALSE;
        }

        $result = @ftp_chdir($this->conn_id, $path);

        if ($result === FALSE && $supress_debug === TRUE)
        {
            throw new Exception('FTP unable to changedir');
        }

        return TRUE;
    }

    /**
     * Create a directory
     *
     * @param string $path
     * @param null $permissions
     * @return bool
     * @throws Exception
     */
    public function mkdir($path = '', $permissions = NULL)
    {
        if ($path === '' OR ! $this->_is_conn())
        {
            return FALSE;
        }

        $result = @ftp_mkdir($this->conn_id, $path);

        if ($result === FALSE)
        {
            throw new Exception('FTP unable to mkdir');
        }

        // Set file permissions if needed
        if ( ! is_null($permissions))
        {
            $this->chmod($path, (int) $permissions);
        }

        return TRUE;
    }

    /**
     * Upload a file to the server
     *
     * @param $locpath
     * @param $rempath
     * @param string $mode
     * @param null $permissions
     * @return bool
     * @throws Exception
     */
    public function upload($locpath, $rempath, $mode = 'auto', $permissions = NULL)
    {
        if ( ! $this->_is_conn())
        {
            return FALSE;
        }

        if ( ! file_exists($locpath) )
        {
            throw new Exception('FTP no source file');
        }

        // Set the mode if not specified
        if ($mode === 'auto')
        {
            // Get the file extension so we can set the upload type
            $ext = $this->_getext($locpath);
            $mode = $this->_settype($ext);
        }

        if ( ftp_alloc( $this->conn_id, filesize($locpath), $result) ) {
            throw new Exception('Unable to allocate space on server. Server said: :result',
                array(':result' => $result )
            );
        }

        $mode = ($mode === 'ascii') ? FTP_ASCII : FTP_BINARY;

        $result = @ftp_put($this->conn_id, $rempath, $locpath, $mode);

        if ($result === FALSE)
        {
            throw new Exception('FTP unable to upload');
        }

        // Set file permissions if needed
        if ( ! is_null($permissions))
        {
            $this->chmod($rempath, (int) $permissions);
        }

        return TRUE;
    }

    /**
     * Download a file from a remote server to the local server
     * @param $rempath
     * @param $locpath
     * @param string $mode
     * @return bool
     * @throws Exception
     */
    public function download($rempath, $locpath, $mode = 'auto')
    {
        if ( ! $this->_is_conn())
        {
            return FALSE;
        }

        // Set the mode if not specified
        if ($mode === 'auto')
        {
            // Get the file extension so we can set the upload type
            $ext = $this->_getext($rempath);
            $mode = $this->_settype($ext);
        }

        $mode = ($mode === 'ascii') ? FTP_ASCII : FTP_BINARY;

        $result = @ftp_get($this->conn_id, $locpath, $rempath, $mode);

        if ($result === FALSE)
        {
            throw new Exception('FTP unable to download');
        }

        return TRUE;
    }

    /**
     * Rename (or move) a file
     *
     * @param $old_file
     * @param $new_file
     * @param bool $move
     * @return bool
     * @throws Exception
     */
    public function rename($old_file, $new_file, $move = FALSE)
    {
        if ( ! $this->_is_conn())
        {
            return FALSE;
        }

        $result = @ftp_rename($this->conn_id, $old_file, $new_file);

        if ($result === FALSE)
        {
            $msg = ($move === FALSE) ? 'rename' : 'move';

            throw new Exception('FTP unale to :msg',
                array(':mover', $mover)
            );
        }

        return TRUE;
    }

    /**
     * Move a file
     *
     * @param $old_file
     * @param $new_file
     * @return bool
     * @throws Exception
     */
    public function move($old_file, $new_file)
    {
        if ( ! $this->_is_conn())
        {
            return FALSE;
        }
        return $this->rename($old_file, $new_file, TRUE);
    }

    /**
     * Delete file
     *
     * @param $filepath
     * @return bool
     * @throws Exception
     */
    public function delete_file($filepath)
    {
        if ( ! $this->_is_conn())
        {
            return FALSE;
        }

        $result = @ftp_delete($this->conn_id, $filepath);

        if ($result === FALSE)
        {
            throw new Exception('FTP unable to delete');
        }

        return TRUE;
    }

    /**
     * Delete a folder and recursively delete everything (including sub-folders)
     *
     * @param $filepath
     * @return bool
     * @throws Exception
     */
    public function delete_dir($filepath)
    {
        if ( ! $this->_is_conn())
        {
            return FALSE;
        }

        // Add a trailing slash to the file path if needed
        $filepath = preg_replace("/(.+?)\/*$/", "\\1/",  $filepath);
        $list = $this->list_files($filepath);

        if ($list !== FALSE AND count($list) > 0)
        {
            foreach ($list as $item)
            {
                // If we can't delete the item it's probaly a folder so
                // we'll recursively call delete_dir()
                if ( ! @ftp_delete($this->conn_id, $item))
                {
                    $this->delete_dir($item);
                }
            }
        }

        $result = @ftp_rmdir($this->conn_id, $filepath);

        if ($result === FALSE)
        {
            throw new Exception('FTP unable to delete :path', [':path'=>$filepath]);
            return FALSE;
        }

        return TRUE;
    }

    /**
     * Set file permissions
     *
     * @param $path the file path
     * @param $perm the permissions
     * @return bool
     * @throws Exception
     */
    public function chmod($path, $perm)
    {
        if ( ! $this->_is_conn() )
        {
            return FALSE;
        }

        // Permissions can only be set when running PHP 5
        if ( ! function_exists('ftp_chmod'))
        {
            throw new Exception('FTP unable to chmod');
        }

        $result = @ftp_chmod($this->conn_id, $perm, $path);

        if ($result === FALSE)
        {
            throw new Exception('FTP unable to chmod');
        }

        return TRUE;
    }

    /**
     * FTP List files in the specified directory
     * @param string $path
     * @return array|bool
     * @throws Exception
     */
    public function list_files($path = '.')
    {
        if ( ! $this->_is_conn() )
        {
            return FALSE;
        }

        return ftp_nlist($this->conn_id, $path);
    }

    /**
     * Returns the system type identifier of the remote FTP server.
     *
     * @return bool|string
     * @throws Exception
     */
    public function systype()
    {
        if ( ! $this->_is_conn() )
        {
            return FALSE;
        }

        return ftp_systype( $this->conn_id );
    }

    /**
     * FTP Size of a specified file
     * @param string $filepath
     * @param bool $formated
     * @return bool|int|string Returns the file size on success, or -1 on error
     * @throws Exception
     */
    public function file_size($filepath = '.', $formated = FALSE)
    {
        if ( ! $this->_is_conn() )
        {
            return FALSE;
        }

        $size = (int) @ftp_size($this->conn_id, $filepath);
        return ( FALSE === $formated ) ? $size : self::_sizeFormat($size) ;
    }

    /**
     * Size Format
     * @private
     * @param   int     Size in bytes
     * @return  string  Returns the formated
     */
    private static function _sizeFormat( $size = 0 )
    {
        if( $size < 1024 )
        {
            $return = $size." bytes";
        }
        else if( $size < 1048576 )
        {
            $size = round ( $size / 1024, 1 );
            $return = $size." KB";
        }
        else if( $size < ( 1073741824 ) )
        {
            $size= round( $size / 1048576, 1 );
            $return = $size." MB";
        }
        else
        {
            $size = round( $size / 1073741824, 1 );
            $return = $size." GB";
        }
        return (string) $return;
    }

    /**
     * FTP File exists
     * @param string $filepath
     * @return bool
     * @throws Exception
     */
    public function file_exists($filepath = '.')
    {
        if ( ! $this->_is_conn() )
        {
            return FALSE;
        }
        return (bool) ( $this->ftp_size($filepath) !== -1 );
    }

    /**
     * FTP Last modified time of the given file
     * @param string $filepath
     * @return bool|int Returns the file size on success, or -1 on error
     * @throws Exception
     */
    public function filemtime($filepath = '.')
    {
        if ( ! $this->_is_conn() )
        {
            return FALSE;
        }
        return (int) @ftp_mdtm($this->conn_id, $filepath);
    }

    /**
     * FTP Get dir exists
     * @param string $path
     * @return bool
     * @throws Exception
     */
    public function dir_exists( $path = '.' )
    {
        return (bool) ( ! $this->_is_conn() || @ftp_chdir($this->conn_id, $path) );
    }

    /**
     * Read a directory and recreate it remotely
     *
     * This function recursively reads a folder and everything it contains (including
     * sub-folders) and creates a mirror via FTP based on it.  Whatever the directory structure
     * of the original file path will be recreated on the server.
     *
     * @param $locpath path to source with trailing slash
     * @param $rempath path to destination - include the base folder with trailing slash
     * @return bool
     * @throws Exception
     */
    public function mirror($locpath, $rempath)
    {
        if ( ! $this->_is_conn())
        {
            return FALSE;
        }

        // Open the local file path
        if ($fp = @opendir($locpath))
        {
            // Attempt to open the remote file path.
            if ( ! $this->changedir($rempath, TRUE))
            {
                // If it doesn't exist we'll attempt to create the direcotory
                if ( ! $this->mkdir($rempath) OR ! $this->changedir($rempath))
                {
                    return FALSE;
                }
            }

            // Recursively read the local directory
            while (FALSE !== ($file = readdir($fp)))
            {
                if (@is_dir($locpath.$file) && substr($file, 0, 1) != '.')
                {
                    $this->mirror($locpath.$file."/", $rempath.$file."/");
                }
                elseif (substr($file, 0, 1) != ".")
                {
                    // Get the file extension so we can se the upload type
                    $ext = $this->_getext($file);
                    $mode = $this->_settype($ext);

                    $this->upload($locpath.$file, $rempath.$file, $mode);
                }
            }

            return TRUE;
        }

        return FALSE;
    }


    /**
     * Extract the file extension
     *
     * @access  private
     * @param   string
     * @return  string
     */
    private function _getext($filename)
    {
        if (FALSE === strpos($filename, '.'))
        {
            return 'txt';
        }

        $x = explode('.', $filename);
        return (string) end($x);
    }


    /**
     * Set the upload type
     *
     * @access  private
     * @param   string
     * @return  string
     */
    private function _settype($ext)
    {
        $text_types = array(
                            'txt',
                            'text',
                            'php',
                            'phps',
                            'php4',
                            'js',
                            'css',
                            'htm',
                            'html',
                            'phtml',
                            'shtml',
                            'log',
                            'xml'
                            );

        return (bool) (in_array($ext, $text_types)) ? 'ascii' : 'binary';
    }

    /**
     * @return bool
     * @throws Exception
     */
    public function close()
    {
        if ( ! $this->_is_conn())
        {
            return FALSE;
        }
        return $this->connected = @ftp_close( $this->conn_id );
    }

    /**
     * @throws Exception
     */
    public function __destruct()
    {
        $this->close();
    }
}
