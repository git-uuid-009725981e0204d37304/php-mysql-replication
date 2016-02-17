<?php

namespace MySQLReplication\BinLog;

use MySQLReplication\DataBase\DBHelper;
use MySQLReplication\Definitions\ConstEventType;
use MySQLReplication\Definitions\ConstMy;
use MySQLReplication\Exception\BinLogException;
use MySQLReplication\Pack\RowEvent;

/**
 * Class BinLogPack
 */
class BinLogPack
{
    /**
     * @var []
     */
    private $eventInfo;
    /**
     * @var int
     */
    private $readBytes = 0;
    /**
     * @var string
     */
    private $buffer = '';
    /**
     * @var DBHelper
     */
    private $DBHelper;

    public function __construct(DBHelper $DBHelper)
    {
        $this->DBHelper = $DBHelper;
    }

    /**
     * @param $pack
     * @param bool|true $checkSum
     * @param array $ignoredEvents
     * @param array $onlyTables
     * @param array $onlyDatabases
     * @return array
     */
    public function init(
        $pack,
        $checkSum = true,
        array $ignoredEvents = [],
        array $onlyTables = [],
        array $onlyDatabases = []
    ) {
        $this->buffer = $pack;
        $this->readBytes = 0;
        $this->eventInfo = [];

        // "ok" value on first byte
        $this->advance(1);

        $this->eventInfo = unpack('Vtime/Ctype/Vid/Vsize/Vpos/vflag', $this->read(19));

        $event_size_without_header = true === $checkSum ? ($this->eventInfo['size'] - 23) : ($this->eventInfo['size'] - 19);

        $data = [];

        if (in_array($this->eventInfo['type'], $ignoredEvents))
        {
            return $data;
        }

        if ($this->eventInfo['type'] == ConstEventType::TABLE_MAP_EVENT)
        {
            $data = RowEvent::tableMap($this, $this->DBHelper, $this->eventInfo['type']);
        }
        elseif (in_array($this->eventInfo['type'], [ConstEventType::UPDATE_ROWS_EVENT_V1, ConstEventType::UPDATE_ROWS_EVENT_V2]))
        {
            $data = RowEvent::updateRow($this, $this->eventInfo['type'], $event_size_without_header, $onlyTables, $onlyDatabases);
        }
        elseif (in_array($this->eventInfo['type'], [ConstEventType::WRITE_ROWS_EVENT_V1, ConstEventType::WRITE_ROWS_EVENT_V2]))
        {
            $data = RowEvent::addRow($this, $this->eventInfo['type'], $event_size_without_header, $onlyTables, $onlyDatabases);
        }
        elseif (in_array($this->eventInfo['type'], [ConstEventType::DELETE_ROWS_EVENT_V1, ConstEventType::DELETE_ROWS_EVENT_V2]))
        {
            $data = RowEvent::delRow($this, $this->eventInfo['type'], $event_size_without_header, $onlyTables, $onlyDatabases);
        }
        elseif ($this->eventInfo['type'] == ConstEventType::XID_EVENT)
        {
            $data['xid'] = $this->readUInt64();
        }
        elseif ($this->eventInfo['type'] == ConstEventType::ROTATE_EVENT)
        {
            $pos = $this->readUInt64();
            $binFileName = $this->read($event_size_without_header - 8);

            $data['rotate'] = ['position' => $pos, 'next_binlog' => $binFileName];
        }
        elseif ($this->eventInfo['type'] == ConstEventType::GTID_LOG_EVENT)
        {
            //gtid event
            $commit_flag = $this->readUInt8() == 1;
            $sid = unpack('H*', $this->read(16))[1];
            $gno = $this->readUInt64();

            $data['gtid_log_event'] = [
                'commit_flag' => $commit_flag,
                'sid' => $sid,
                'gno' => $gno,
                'gtID' => vsprintf('%s%s%s%s%s%s%s%s-%s%s%s%s-%s%s%s%s-%s%s%s%s-%s%s%s%s%s%s%s%s%s%s%s%s', str_split($sid)) . ':' . $gno
            ];
        }
        else if ($this->eventInfo['type'] == ConstEventType::QUERY_EVENT)
        {
            $slave_proxy_id = $this->readUInt32();
            $execution_time = $this->readUInt32();
            $schema_length = $this->readUInt8();
            $error_code = $this->readUInt16();
            $status_vars_length = $this->readUInt16();

            $status_vars = $this->read($status_vars_length);
            $schema = $this->read($schema_length);
            $this->advance(1);

            $query = $this->read($this->eventInfo['size'] - 36 - $status_vars_length - $schema_length - 1);

            $data['query_event'] = [
                'slave_proxy_id' => $slave_proxy_id,
                'execution_time' => $execution_time,
                'schema' => $schema,
                'error_code' => $error_code,
                'status_vars' => bin2hex($status_vars),
                'query' => $query,
            ];
        }

        $data['event'] = $this->eventInfo;

        return $data;
    }

    /**
     * @param $length
     */
    public function advance($length)
    {
        $this->read($length);
    }

    /**
     * @param int $length
     * @return string
     * @throws BinLogException
     */
    public function read($length)
    {
        $length = (int)$length;
        $return = substr($this->buffer, 0, $length);
        $this->readBytes += $length;
        $this->buffer = substr($this->buffer, $length);
        return $return;
    }

    /**
     * @return int
     */
    public function readUInt64()
    {
        return $this->unpackUInt64($this->read(8));
    }

    /**
     * @param $data
     * @return string
     */
    public function unpackUInt64($data)
    {
        $data = unpack('V*', $data);
        return bcadd($data[1], bcmul($data[2], bcpow(2, 32)));
    }

    /**
     * @return int
     */
    public function readUInt8()
    {
        return unpack('C', $this->read(1))[1];
    }

    /**
     * @return int
     */
    public function readUInt32()
    {
        return unpack('I', $this->read(4))[1];
    }

    /**
     * @return int
     */
    public function readUInt16()
    {
        return unpack('v', $this->read(2))[1];
    }

    /**
     * Push again data in data buffer. It's use when you want
     * to extract a bit from a value a let the rest of the code normally
     * read the data
     *
     * @param string $data
     */
    public function unread($data)
    {
        $this->readBytes -= strlen($data);
        $this->buffer = $data . $this->buffer;
    }

    /**
     * @see read a 'Length Coded Binary' number from the data buffer.
     * Length coded numbers can be anywhere from 1 to 9 bytes depending
     * on the value of the first byte.
     * From PyMYSQL source code
     *
     * @return int|string
     */
    public function readCodedBinary()
    {
        $c = ord($this->read(1));
        if ($c == ConstMy::NULL_COLUMN)
        {
            return '';
        }
        if ($c < ConstMy::UNSIGNED_CHAR_COLUMN)
        {
            return $c;
        }
        elseif ($c == ConstMy::UNSIGNED_SHORT_COLUMN)
        {
            return $this->readUInt16();

        }
        elseif ($c == ConstMy::UNSIGNED_INT24_COLUMN)
        {
            return $this->readUInt24();
        }
        elseif ($c == ConstMy::UNSIGNED_INT64_COLUMN)
        {
            return $this->readUInt64();
        }
        return $c;
    }

    /**
     * @return mixed
     */
    public function readUInt24()
    {
        $data = unpack('C3', $this->read(3));
        return $data[1] + ($data[2] << 8) + ($data[3] << 16);
    }

    /**
     * @return int
     */
    public function readInt24()
    {
        $data = unpack('C3', $this->read(3));

        $res = $data[1] | ($data[2] << 8) | ($data[3] << 16);
        if ($res >= 0x800000)
        {
            $res -= 0x1000000;
        }
        return $res;
    }

    /**
     * @return mixed
     */
    public function readInt64()
    {
        return unpack('q', $this->read(8))[1];
    }

    /**
     * @param $size
     * @return string
     * @throws BinLogException
     */
    public function readLengthCodedPascalString($size)
    {
        return $this->read($this->readUIntBySize($size));
    }

    /**
     * Read a little endian integer values based on byte number
     *
     * @param $size
     * @return mixed
     * @throws BinLogException
     */
    public function readUIntBySize($size)
    {
        if ($size == 1)
        {
            return $this->readUInt8();
        }
        elseif ($size == 2)
        {
            return $this->readUInt16();
        }
        elseif ($size == 3)
        {
            return $this->readUInt24();
        }
        elseif ($size == 4)
        {
            return $this->readUInt32();
        }
        elseif ($size == 5)
        {
            return $this->readUInt40();
        }
        elseif ($size == 6)
        {
            return $this->readUInt48();
        }
        elseif ($size == 7)
        {
            return $this->readUInt56();
        }
        elseif ($size == 8)
        {
            return $this->readUInt64();
        }

        throw new BinLogException('$size ' . $size . ' not handled');
    }

    /**
     * @return mixed
     */
    public function readUInt40()
    {
        $data = unpack('CI', $this->read(5));
        return $data[1] + ($data[2] << 8);
    }

    /**
     * @return mixed
     */
    public function readUInt48()
    {
        $data = unpack('v3', $this->read(6));
        return $data[1] + ($data[2] << 16) + ($data[3] << 32);
    }

    /**
     * @return mixed
     */
    public function readUInt56()
    {
        $data = unpack('CSI', $this->read(7));
        return $data[1] + ($data[2] << 8) + ($data[3] << 24);
    }

    /**
     * Read a big endian integer values based on byte number
     *
     * @param int $size
     * @return int
     * @throws BinLogException
     */
    public function readIntBeBySize($size)
    {
        if ($size == 1)
        {
            return unpack('c', $this->read($size))[1];
        }
        elseif ($size == 2)
        {
            return unpack('n', $this->read($size))[1];
        }
        elseif ($size == 3)
        {
            return $this->readInt24Be();
        }
        elseif ($size == 4)
        {
            return unpack('i', strrev($this->read(4)))[1];
        }
        elseif ($size == 5)
        {
            return $this->readInt40Be();
        }
        elseif ($size == 8)
        {
            return unpack('l', $this->read($size))[1];
        }

        throw new BinLogException('$size ' . $size . ' not handled');
    }

    /**
     * @return int
     */
    public function readInt24Be()
    {
        $data = unpack('C3', $this->read(3));
        $res = ($data[1] << 16) | ($data[2] << 8) | $data[3];
        if ($res >= 0x800000)
        {
            $res -= 0x1000000;
        }
        return $res;
    }

    /**
     * @return mixed
     */
    public function readInt40Be()
    {
        $data1 = unpack('N', $this->read(4))[1];
        $data2 = unpack('C', $this->read(1))[1];
        return $data2 + ($data1 << 8);
    }

    /**
     * @param $size
     * @return bool
     */
    public function isComplete($size)
    {
        if ($this->readBytes + 1 - 20 < $size)
        {
            return false;
        }
        return true;
    }
}