<?php

# Simple abstraction layer for MySQL.

class absdb {

    var $connector = false;
    var $result = false;
    var $sQuery = false;
    var $params = array();
    var $types = null;
    var $err = null;
    var $empty_result = '';
    var $affected_rows = 0;

    function __construct($host, $user, $pass, $db) {
        // opens connection to database
        $this->connector = new MySQLi($host, $user, $pass, $db);
        # If connection failure, simply die
        if (mysqli_connect_error())
            die('ABSDB Connect error (' . $this->connector->connect_errno() . ') ' . $this->connector->connect_error());
    }

    function __toString() {
        return 'Instance of absdb MySQLi DB abstraction.';
    }

    function error() {

        $this->err = $this->connector->errno;

        if (0 === error_reporting())
            return;

        ob_start();

        echo 'ABSDB Query: ' . $this->sQuery . ' - ' . $this->types . '<br />';
        echo 'ABSDB Error: (' . $this->connector->errno . ') ' . $this->connector->error . '<br />';
        if ($this->empty_result) {
            echo 'ABDSB Empty Set Error: ' . $this->empty_result . '<br />';
        }
        echo '<br /><pre>';
        print_r($this->params);
        ;
        echo '</pre>';

        $message = ob_get_contents();
        ob_end_clean();

        echo $message;
    }

    function preparse_prepared() {
        $pos = -1; # position to start search in query
        $offset = 0; # offset from index to position in type string
        $new_params = $this->params; # copy of params to manipulate

        for ($idx = 0; $idx < count($this->params); $idx++) {
            # search for ? in query	
            if (!$pos = strPos($this->sQuery, '?', $pos + 1))
                break;
            # isolate value at index
            $param = $this->params[$idx];
            # test if value should actually be null, and set matches to null
            if (isSet($this->types[$idx - $offset]) && ((($this->types[$idx - $offset] == 'i' || $this->types[$idx - $offset] == 'd') && $param === '') || ($this->types[$idx - $offset] == 's' && $param == '--null')))
                $param = null;
            # check null
            if (!is_null($param))
                continue;
            # change ? in query to NULL
            $this->sQuery = subStr_replace($this->sQuery, 'NULL', $pos, 1);
            # remove value from new params list
            unset($new_params[$idx]);
            # remove the type definition from the types string
            $this->types = subStr_replace($this->types, '', $idx - $offset, 1);
            # increment the offset, to indicate type string is a character shorter
            $offset++;
        }
        $this->params = array_values($new_params);
    }

    function get_result($single = false) {
        // Function lifted from http://us2.php.net/manual/en/mysqli-stmt.bind-result.php#81350
        $result = array();
        $metadata = $this->STMT->result_metadata();
        $fields = $metadata->fetch_fields();

        for (;;) {
            $pointers = array();
            $row = new stdClass();
            $pointers[] = $this->STMT;
            foreach ($fields as $field) {
                $fieldname = $field->name;
                $pointers[] = &$row->$fieldname;
            }
            call_user_func_array('mysqli_stmt_bind_result', $pointers);
            if (!$this->STMT->fetch())
                break;
            $result[] = $row;
            if ($single)
                break;
        }

        $metadata->free();

        if ($this->empty_result && !count($result)) {
            $this->error();
        }

        if ($single) {
            return isSet($result[0]) ? $result[0] : null; // return 1 object
        } else {
            return $result; // return all objects
        }
    }

    function get_query() {
        // Returns a string version of the query
        // Presently pre-substitution, should be adjusted to show the variables that have been inserted
        return $this->sQuery;
    }

    function refValues($arr) {
        if (strnatcmp(phpversion(), '5.3') >= 0) { //Reference is required for PHP 5.3+
            $refs = array();
            foreach ($arr as $key => $value)
                $refs[$key] = &$arr[$key];
            return $refs;
        }
        return $arr;
    }

    function query($query = null, $types = null, $params = array(), $empty_result = '') {
        // Executes a query against the database and returns the insert_id for the query where applicable
        if ($query) { // skip if null query
            $this->sQuery = $query;
            $this->err = null;
            $this->empty_result = $empty_result;
            $this->types = $types;
            $this->params = $params;
            $this->preparse_prepared();

            $this->STMT = $this->connector->prepare($this->sQuery);

            if ($types && $this->STMT) {
                if (count($this->params)) {
                    if (!call_user_func_array(array($this->STMT, 'bind_param'), array_merge(array($this->types), $this->refValues($this->params)))) {
                        $this->error();
                    }
                }
            } elseif (!$this->STMT) {
                $this->error();
            }
        }
        # If no query, STMT may be from previous execution, we may still want to execute
        if (isSet($this->STMT) && $this->STMT) { // check for valid statement object
            $this->STMT->execute();
            if ($this->connector->errno)
                $this->error();
            $this->affected_rows = $this->connector->affected_rows;
            return $this->STMT->insert_id; // Record primary key
        }
        return null; // return nothing if we got here
    }

    function get_object($query = null, $types = null, $params = array(), $empty_result = '') {
        // returns a single stClass object representing first matching row
        if ($query)
            $this->query($query, $types, $params, $empty_result);
        return $this->STMT ? $this->get_result(true) : null;
    }

    function get_objects($query = null, $types = null, $params = array(), $empty_result = '') {
        // returns an array of stCladd objects representing matching rows
        $this->query($query, $types, $params, $empty_result);
        return $this->STMT ? $this->get_result() : array();
    }

}
