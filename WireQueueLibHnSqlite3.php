<?php
/*****************************************************************************
  * @script_type -  PHP-CLASSES
  * @php_version -  5.3.0
  * @scriptname  -  hn_sqlite3.class.php
  * @version     -  0.3
  * @SOURCE-ID   -  1.7
  * @initial     -  10.12.2010
  * -------------------------------------------------------------------------
  * @author      -  Horst Nogajski
  * @copyright   -  (c) 1999 - 2012
*****************************************************************************/



class WireQueueLibHnSqlite3 extends SQLite3 {

    private $db          = null;
    private $fn          = null;
    private $res         = null;
    private $fields      = null;

    public $version      = '';

    public $numrows      = null;
    public $numcols      = null;
    public $fieldnames   = null;

    public $errormsg     = '';

    private $columTypes  = array(
                            SQLITE3_INTEGER => 'integer',
                            SQLITE3_FLOAT   => 'float',
                            SQLITE3_TEXT    => 'string',
                            SQLITE3_BLOB    => 'binary',
                            SQLITE3_NULL    => 'null');


    public function __construct() {
        $tmp = parent::version();
        $this->version = $tmp['versionString'];
    }


    public function __destruct() {
        $this->close();
    }


    /**
     * @param $db_filename = string fullpath to db-file
     * @param $access_mode = integer [ 0 = readonly | 1 = writeable | 2 = createIfNotExist ] default in parent is 3 (writeable + createIfNotExist)
    **/
    public function open($db_filename, $access_mode = 0, $encryptionKey = '') {
        $create = false;
        switch($access_mode) {
            case 3:
            case 2:
                $mode = SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE;
                $create = true;
                break;
            case 1:
                $mode = SQLITE3_OPEN_READWRITE;
                break;
            default:
                $mode = SQLITE3_OPEN_READONLY;
                break;
        }

        // first check for file
        if((!$create && !file_exists($db_filename)) ||
           (!$create && $mode==0 && !is_readable($db_filename)) ||
           (!$create && $mode==1 && (!is_writable($db_filename) || !is_readable($db_filename)))) {
            return false;
        }

        // open file
        $this->fn = $db_filename;
        parent::open($db_filename, $mode, $encryptionKey);

        // check if file is a valid db
        $check = @parent::querySingle('SELECT count(*) FROM sqlite_master');
        $code  = @parent::lastErrorCode();
        if(0 < $check || (0 == $check && 0 == $code)) {
            return true;
        } else {
            $this->errormsg = parent::lastErrorMsg();
            return false;
        }
    }


    public function create_table_if_not_exist($tablename, $fieldsdefinition) {
        // check if tablename is already present
        $check = @parent::querySingle("SELECT count(*) FROM $tablename");
        $code  = @parent::lastErrorCode();
        if(0 < $check || (0 == $check && 0 == $code)) {
            return true;
        } else {
            $sql = "CREATE TABLE $tablename ($fieldsdefinition)";
            $res = $this->exec($sql);
            return ($res !== false) ? true : false;
        }
    }


    public function query($q, $result_type = SQLITE3_ASSOC) {
        // reset result of optional previous query
        $this->records    = null;
        $this->numrows    = null;
        $this->numcols    = null;
        $this->fieldnames = null;
        $this->fields     = null;
        // execute query
        $this->res = parent::query($q);
        // check result
        if(true === $this->res) {

            // query was successful, but returns no results, (INSERT, UPDATE, DELETE, etc)
            // we fetch number of affected rows
            $this->numrows = parent::changes();
            return true;

        } elseif(is_object($this->res)) {

            // query was successful and returns a result-object
            $this->records = array();
            while($row = $this->res->fetchArray($result_type)) {
                $this->records[] = $row;
            }
            $this->numrows = count($this->records);
            $this->numcols = $this->res->numColumns();
            $this->fields = array();
            for($i = 0; $i < $this->numcols; $i++) {
                #// columnType funktioniert nicht richtig
                #$this->fields[$this->res->columnName($i)] = $this->columTypes[$this->res->columnType($i)];
                $this->fields[$this->res->columnName($i)] = 'SQLite3 only has column affinity';
            }
            $this->fieldnames = array_keys($this->fields);
            $this->res->finalize();
            return true;

        } else {

            return false;
        }
    }


    public function querySingle($q, $entire_row = false) {
        // execute query
        $res = parent::querySingle($q, $entire_row);
        if(false === $res) {
            // query was not successful
            return false;
        }
        if(null === $res || ($entire_row && is_array($res) && 0 === count($res))) {
            // query was successful, but returns no results
            return null;
        }
        // query was successful and returns a result
        return $entire_row ? $res : trim($res);
    }


    public function exec($q) {
        if(parent::exec($q) === false) {
            $this->errormsg = parent::lastErrorMsg();
            return false;
        }
        return intval(parent::changes());
    }

}
