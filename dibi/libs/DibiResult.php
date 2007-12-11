<?php

/**
 * dibi - tiny'n'smart database abstraction layer
 * ----------------------------------------------
 *
 * Copyright (c) 2005, 2007 David Grudl aka -dgx- (http://www.dgx.cz)
 *
 * This source file is subject to the "dibi license" that is bundled
 * with this package in the file license.txt.
 *
 * For more information please see http://dibiphp.com/
 *
 * @copyright  Copyright (c) 2005, 2007 David Grudl
 * @license    http://dibiphp.com/license  dibi license
 * @link       http://dibiphp.com/
 * @package    dibi
 */



/**
 * dibi result-set abstract class
 *
 * <code>
 * $result = dibi::query('SELECT * FROM [table]');
 *
 * $row   = $result->fetch();
 * $value = $result->fetchSingle();
 * $table = $result->fetchAll();
 * $pairs = $result->fetchPairs();
 * $assoc = $result->fetchAssoc('id');
 * $assoc = $result->fetchAssoc('active,#,id');
 *
 * unset($result);
 * </code>
 *
 * @author     David Grudl
 * @copyright  Copyright (c) 2005, 2007 David Grudl
 * @package    dibi
 * @version    $Revision$ $Date$
 */
class DibiResult extends NObject implements IteratorAggregate, Countable
{
    /**
     * DibiDriverInterface
     * @var array
     */
    private $driver;

    /**
     * Translate table
     * @var array
     */
    private $xlat;

    /**
     * Cache for $driver->getColumnsMeta()
     * @var array
     */
    private $metaCache;


    /**
     * Already fetched? Used for allowance for first seek(0)
     * @var bool
     */
    private $fetched = FALSE;

    /**
     * Qualifiy each column name with the table name?
     * @var array|FALSE
     */
    private $withTables = FALSE;



    private static $types = array(
        dibi::FIELD_TEXT =>    'string',
        dibi::FIELD_BINARY =>  'string',
        dibi::FIELD_BOOL =>    'bool',
        dibi::FIELD_INTEGER => 'int',
        dibi::FIELD_FLOAT =>   'float',
        dibi::FIELD_COUNTER => 'int',
    );




    public function __construct($driver)
    {
        $this->driver = $driver;
    }


    /**
     * Automatically frees the resources allocated for this result set
     *
     * @return void
     */
    public function __destruct()
    {
        @$this->free();
    }



    /**
     * Returns the resultset resource
     *
     * @return mixed
     */
    final public function getResource()
    {
        return $this->getDriver()->getResultResource();
    }



    /**
     * Moves cursor position without fetching row
     *
     * @param  int      the 0-based cursor pos to seek to
     * @return boolean  TRUE on success, FALSE if unable to seek to specified record
     * @throws DibiException
     */
    final public function seek($row)
    {
        return ($row !== 0 || $this->fetched) ? (bool) $this->getDriver()->seek($row) : TRUE;
    }



    /**
     * Returns the number of rows in a result set
     *
     * @return int
     */
    final public function rowCount()
    {
        return $this->getDriver()->rowCount();
    }



    /**
     * Frees the resources allocated for this result set
     *
     * @return void
     */
    final public function free()
    {
        if ($this->driver !== NULL) {
            $this->driver->free();
            $this->driver = NULL;
        }
    }



    /**
     * Qualifiy each column name with the table name?
     *
     * @param  bool
     * @return void
     * @throws DibiException
     */
    final public function setWithTables($val)
    {
        if ($val) {
            if ($this->metaCache === NULL) {
                $this->metaCache = $this->getDriver()->getColumnsMeta();
            }

            $cols = array();
            foreach ($this->metaCache as $col) {
                // intentional ==
                $name = $col['table'] == '' ? $col['name'] : ($col['table'] . '.' . $col['name']);
                if (isset($cols[$name])) {
                    $fix = 1;
                    while (isset($cols[$name . '#' . $fix])) $fix++;
                    $name .= '#' . $fix;
                }
                $cols[$name] = TRUE;
            }
            $this->withTables = array_keys($cols);

        } else {
            $this->withTables = FALSE;
        }
    }



    /**
     * Qualifiy each key with the table name?
     *
     * @return bool
     */
    final public function getWithTables()
    {
        return (bool) $this->withTables;
    }



    /**
     * Fetches the row at current position, process optional type conversion
     * and moves the internal cursor to the next position
     *
     * @return array|FALSE  array on success, FALSE if no next record
     */
    final public function fetch()
    {
        if ($this->withTables === FALSE) {
            $row = $this->getDriver()->fetch(TRUE);
            if (!is_array($row)) return FALSE;

        } else {
            $row = $this->getDriver()->fetch(FALSE);
            if (!is_array($row)) return FALSE;
            $row = array_combine($this->withTables, $row);
        }

        $this->fetched = TRUE;

        // types-converting?
        if ($this->xlat !== NULL) {
            foreach ($this->xlat as $col => $type) {
                if (isset($row[$col])) {
                    $row[$col] = $this->convert($row[$col], $type);
                }
            }
        }

        return $row;
    }



    /**
     * Like fetch(), but returns only first field
     *
     * @return mixed  value on success, FALSE if no next record
     */
    final function fetchSingle()
    {
        $row = $this->getDriver()->fetch(TRUE);
        if (!is_array($row)) return FALSE;
        $this->fetched = TRUE;
        $value = reset($row);

        // types-converting?
        $key = key($row);
        if (isset($this->xlat[$key])) {
            return $this->convert($value, $this->xlat[$key]);
        }

        return $value;
    }



    /**
     * Fetches all records from table.
     *
     * @return array
     */
    final function fetchAll()
    {
        $this->seek(0);
        $row = $this->fetch();
        if (!$row) return array();  // empty resultset

        $data = array();
        if (count($row) === 1) {
            $key = key($row);
            do {
                $data[] = $row[$key];
            } while ($row = $this->fetch());

        } else {

            do {
                $data[] = $row;
            } while ($row = $this->fetch());
        }

        return $data;
    }



    /**
     * Fetches all records from table and returns associative tree
     * Associative descriptor:  assoc1,#,assoc2,=,assoc3,@
     * builds a tree:           $data[assoc1][index][assoc2]['assoc3']->value = {record}
     *
     * @param  string  associative descriptor
     * @return array
     * @throws InvalidArgumentException
     */
    final function fetchAssoc($assoc)
    {
        $this->seek(0);
        $row = $this->fetch();
        if (!$row) return array();  // empty resultset

        $data = NULL;
        $assoc = explode(',', $assoc);

        // check columns
        foreach ($assoc as $as) {
            if ($as !== '#' && $as !== '=' && $as !== '@' && !array_key_exists($as, $row)) {
                throw new InvalidArgumentException("Unknown column '$as' in associative descriptor");
            }
        }

        // strip leading = and @
        $assoc[] = '=';  // gap
        $last = count($assoc) - 1;
        while ($assoc[$last] === '=' || $assoc[$last] === '@') {
            $leaf = $assoc[$last];
            unset($assoc[$last]);
            $last--;

            if ($last < 0) {
                $assoc[] = '#';
                break;
            }
        }

        // make associative tree
        do {
            $x = & $data;

            // iterative deepening
            foreach ($assoc as $i => $as) {
                if ($as === '#') { // indexed-array node
                    $x = & $x[];

                } elseif ($as === '=') { // "record" node
                    if ($x === NULL) {
                        $x = $row;
                        $x = & $x[ $assoc[$i+1] ];
                        $x = NULL; // prepare child node
                    } else {
                        $x = & $x[ $assoc[$i+1] ];
                    }

                } elseif ($as === '@') { // "object" node
                    if ($x === NULL) {
                        $x = (object) $row;
                        $x = & $x->{$assoc[$i+1]};
                        $x = NULL; // prepare child node
                    } else {
                        $x = & $x->{$assoc[$i+1]};
                    }


                } else { // associative-array node
                    $x = & $x[ $row[ $as ] ];
                }
            }

            if ($x === NULL) { // build leaf
                if ($leaf === '=') $x = $row; else $x = (object) $row;
            }

        } while ($row = $this->fetch());

        unset($x);
        return $data;
    }



    /**
     * Fetches all records from table like $key => $value pairs
     *
     * @param  string  associative key
     * @param  string  value
     * @return array
     * @throws InvalidArgumentException
     */
    final function fetchPairs($key = NULL, $value = NULL)
    {
        $this->seek(0);
        $row = $this->fetch();
        if (!$row) return array();  // empty resultset

        $data = array();

        if ($value === NULL) {
            if ($key !== NULL) {
                throw new InvalidArgumentException("Either none or both columns must be specified");
            }

            if (count($row) < 2) {
                throw new LoginException("Result must have at least two columns");
            }

            // autodetect
            $tmp = array_keys($row);
            $key = $tmp[0];
            $value = $tmp[1];

        } else {
            if (!array_key_exists($value, $row)) {
                throw new InvalidArgumentException("Unknown value column '$value'");
            }

            if ($key === NULL) { // indexed-array
                do {
                    $data[] = $row[$value];
                } while ($row = $this->fetch());
                return $data;
            }

            if (!array_key_exists($key, $row)) {
                throw new InvalidArgumentException("Unknown key column '$key'");
            }
        }

        do {
            $data[ $row[$key] ] = $row[$value];
        } while ($row = $this->fetch());

        return $data;
    }



    final public function setType($col, $type = NULL)
    {
        if (is_array($col)) {
            $this->xlat = $col;

        } else {
            $this->xlat[$col] = $type;
        }
    }



    final public function getType($col)
    {
        return isset($this->xlat[$col]) ? $this->xlat[$col] : NULL;
    }



    final public function convert($value, $type)
    {
        if ($value === NULL || $value === FALSE) {
            return $value;
        }

        if (isset(self::$types[$type])) {
            settype($value, self::$types[$type]);
            return $value;
        }

        if ($type === dibi::FIELD_DATE || $type === dibi::FIELD_DATETIME) {
            return strtotime($value);   // !!! not good
        }

        return $value;
    }



    /**
     * Gets an array of meta informations about column
     *
     * @return array
     */
    final public function getColumnsMeta()
    {
        if ($this->metaCache === NULL) {
            $this->metaCache = $this->getDriver()->getColumnsMeta();
        }

        $cols = array();
        foreach ($this->metaCache as $col) {
            $name = (!$this->withTables || $col['table'] === NULL) ? $col['name'] : ($col['table'] . '.' . $col['name']);
            $cols[$name] = $col;
        }
        return $cols;
    }



    /**
     * Displays complete result-set as HTML table for debug purposes
     *
     * @return void
     */
    final public function dump()
    {
        $none = TRUE;
        foreach ($this as $i => $row) {
            if ($none) {
                echo "\n<table class=\"dump\">\n<thead>\n\t<tr>\n\t\t<th>#row</th>\n";

                foreach ($row as $col => $foo) {
                    echo "\t\t<th>" . htmlSpecialChars($col) . "</th>\n";
                }

                echo "\t</tr>\n</thead>\n<tbody>\n";
                $none = FALSE;
            }

            echo "\t<tr>\n\t\t<th>", $i, "</th>\n";
            foreach ($row as $col) {
                //if (is_object($col)) $col = $col->__toString();
                echo "\t\t<td>", htmlSpecialChars($col), "</td>\n";
            }
            echo "\t</tr>\n";
        }

        if ($none) {
            echo '<p><em>empty resultset</em></p>';
        } else {
            echo "</tbody>\n</table>\n";
        }
    }



    /**
     * Required by the IteratorAggregate interface
     * @param  int  offset
     * @param  int  limit
     * @return ArrayIterator
     */
    final public function getIterator($offset = NULL, $limit = NULL)
    {
        return new DibiResultIterator($this, $offset, $limit);
    }



    /**
     * Required by the Countable interface
     * @return int
     */
    final public function count()
    {
        return $this->rowCount();
    }



    /**
     * Safe access to property $driver
     *
     * @return DibiDriverInterface
     * @throws DibiException
     */
    private function getDriver()
    {
        if ($this->driver === NULL) {
            throw new DibiException('Resultset was released from memory');
        }

        return $this->driver;
    }


}
