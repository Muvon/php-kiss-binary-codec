<?php
namespace Muvon\KISS;

use Error;

// Max \x13
define('BC_RAW',    "\x00");
define('BC_BOOL',   "\x01");
define('BC_INT1',   "\x12");
define('BC_INT2',   "\x02");
define('BC_INT4',   "\x03");
define('BC_INT8',   "\x04");
define('BC_UINT1',  "\x13");
define('BC_UINT2',  "\x05");
define('BC_UINT4',  "\x06");
define('BC_UINT8',  "\x07");
define('BC_FLOAT',  "\x08");
define('BC_DOUBLE', "\x09");
define('BC_HEX',    "\x0a");
define('BC_CHAR',   "\x0b");
define('BC_UCHAR',  "\x0c");
define('BC_STR',    "\x0d");
define('BC_LIST',   "\x0e");
define('BC_LIST_UINT1', "\x14");
define('BC_LIST_UINT2', "\x15");
define('BC_LIST_UINT4', "\x16");
define('BC_LIST_UINT8', "\x16");
define('BC_LIST_INT1', "\x17");
define('BC_LIST_INT2', "\x18");
define('BC_LIST_INT4', "\x19");
define('BC_LIST_INT8', "\x1a");
define('BC_LIST_HEX16', "\x1b");
define('BC_LIST_HEX32', "\x1c");
define('BC_LIST_HEX64', "\x1d");
define('BC_MAP',    "\x0f");
define('BC_NUM',    "\x10");
define('BC_NULL',   "\x11");

final class BinaryCodec {
  const PK_MAP = [
    1 => 'C',
    2 => 'n',
    4 => 'N',
  ];

  const SZ_MAP = [
    BC_RAW => 4,
    BC_BOOL => 1,
    BC_INT1 => 1,
    BC_INT2 => 1,
    BC_INT4 => 1,
    BC_INT8 => 1,
    BC_UINT1 => 1,
    BC_UINT2 => 1,
    BC_UINT4 => 1,
    BC_UINT8 => 1,
    BC_FLOAT => 2,
    BC_DOUBLE => 2,
    BC_HEX => 4,
    BC_CHAR => 1,
    BC_UCHAR => 1,
    BC_STR => 4,
    BC_LIST => 4,
    BC_LIST_UINT1 => 4,
    BC_LIST_UINT2 => 4,
    BC_LIST_UINT4 => 4,
    BC_LIST_UINT8 => 4,
    BC_LIST_INT1 => 4,
    BC_LIST_INT2 => 4,
    BC_LIST_INT4 => 4,
    BC_LIST_INT8 => 4,
    BC_LIST_HEX16 => 4,
    BC_LIST_HEX32 => 4,
    BC_LIST_HEX64 => 4,
    BC_MAP => 4,
    BC_NUM => 1,
    BC_NULL => 1,
  ];

  protected array $key_map;

  protected final function __construct(protected array $config) {
    $this->key_map = array_flip(array_keys($config));
  }

  public static function create(array $config): self {
    return new static($config);
  }

  public function pack(mixed $data): string {
    return $this->encode($data, $this->getDataType($data));
  }

  public function unpack(string $binary): mixed {
    $k_type = $binary[0];
    $k_key = $this->decodeKeyName($binary[1]) ?: null;
    $sz_len = static::SZ_MAP[$k_type];
    $k_len = unpack(static::PK_MAP[$sz_len], substr($binary, 2, $sz_len))[1];
    $meta_len = 2 + $sz_len;
    if ($k_len !== (strlen($binary) - $meta_len)) {
      throw new Error('Inconsistent binary data passed');
    }

    return $k_len
      ? $this->decode(substr($binary, $meta_len), $k_type, $k_key)
      : null
    ;
  }

  protected function encode(mixed $data, string $type, ?string $key = "\x00"): string {
    $sz_len = static::SZ_MAP[$type];
    $sz_fmt = static::PK_MAP[$sz_len];
    switch ($type) {
      case BC_LIST:
      case BC_MAP:
        $bin = b'';
        foreach ($data as $k => &$v) {
          if (is_array($v)) {
            $k_type = $this->getKeyType($k);
            $bin .= $this->encode($v, key($v) ? BC_MAP : ($k_type !== BC_RAW ? $k_type : BC_LIST), $this->encodeKeyName($k));
          } else {
            $bin .= $this->encode($v, $this->getDataType($v), $this->encodeKeyName($k));
          }
        }

        return $type . $key . pack($sz_fmt, strlen($bin)) . $bin;
        break;

      case BC_LIST_INT1:
      case BC_LIST_INT2:
      case BC_LIST_INT4:
      case BC_LIST_INT8:
      case BC_LIST_UINT1:
      case BC_LIST_UINT2:
      case BC_LIST_UINT4:
      case BC_LIST_UINT8:
        $bin = pack($this->getPackType($type), ...$data);
        return $type . $key . pack($sz_fmt, strlen($bin)) . $bin;
        break;

      case BC_LIST_HEX16:
      case BC_LIST_HEX32:
      case BC_LIST_HEX64:
        $bin = pack(str_repeat('h*', sizeof($data)), ...$data);
        return $type . $key . pack($sz_fmt, strlen($bin)) . $bin;
        break;

      case BC_BOOL:
        return $type . $key . pack($sz_fmt, 1) . ($data ? "\x01" : "\x00");
        break;

      case BC_HEX:
      case BC_CHAR:
      case BC_UCHAR:
      case BC_INT2:
      case BC_INT4:
      case BC_INT8:
      case BC_UINT2:
      case BC_UINT4:
      case BC_UINT8:
      case BC_FLOAT:
      case BC_DOUBLE:
      case BC_INT1:
      case BC_UINT1:
        return $this->packType($data, $type, $key);
        break;

      case BC_NUM:
        $val = gmp_strval(gmp_init($data, 10), 16);
        if (strlen($val) % 2 !== 0) {
          $val = '0' . $val;
        }
        $bin = hex2bin($val);
        return $type . $key . pack($sz_fmt, strlen($bin)) . $bin;
        break;

      // case BC_STR:
      //   $bin = gzencode($data);
      //   return $type . $key .  pack(static::SIZE_TYPE, strlen($bin)) . $bin;
      //   break;

      case BC_NULL:
        return $type. pack($sz_fmt, 1) . "\x00";
        break;

      default:
        return $type . $key . pack($sz_fmt, strlen($data)) . $data;
    }
  }

  protected function decode(string $binary, string $type, ?string $key = null): mixed {
    $sz_len = static::SZ_MAP[$type];
    $sz_fmt = static::PK_MAP[$sz_len];
    switch ($type) {
      case BC_LIST:
      case BC_MAP:
        $max_index = strlen($binary);
        $i = 0;
        $data = $key ? [$key => []] : [];
        if ($key) {
          $list = &$data[$key];
        } else {
          $list = &$data;
        }

        while ($i < $max_index) {
          $k_type = $binary[$i];
          $sz_len = static::SZ_MAP[$k_type];
          $sz_fmt = static::PK_MAP[$sz_len];

          $k_key = $this->decodeKeyName($binary[$i + 1]) ?: null;
          $meta_len = 2 + $sz_len;
          $k_len = unpack($sz_fmt, substr($binary, $i + 2, $sz_len))[1];
          if ($k_key) {
            $list[$k_key] = $this->decode(substr($binary, $i + $meta_len, $k_len), $k_type, $k_key);
          } else {
            $list[] = $this->decode(substr($binary, $i + $meta_len, $k_len), $k_type, $k_key);
          }
          $i += $k_len + $meta_len;
        }
        return $list;

        break;

      case BC_BOOL:
        return $binary === "\x01";
        break;

      case BC_HEX:
      case BC_CHAR:
      case BC_UCHAR:
      case BC_INT1:
      case BC_INT2:
      case BC_INT4:
      case BC_INT8:
      case BC_UINT1:
      case BC_UINT2:
      case BC_UINT4:
      case BC_UINT8:
      case BC_FLOAT:
      case BC_DOUBLE:
        return $this->unpackType($binary, $type);
        break;

      case BC_LIST_INT1:
      case BC_LIST_INT2:
      case BC_LIST_INT4:
      case BC_LIST_INT8:
      case BC_LIST_UINT1:
      case BC_LIST_UINT2:
      case BC_LIST_UINT4:
      case BC_LIST_UINT8:
        return array_values(unpack($this->getPackType($type), $binary));
        break;

      case BC_LIST_HEX16:
      case BC_LIST_HEX32:
      case BC_LIST_HEX64:
        $sz = match($type) {
          BC_LIST_HEX16 => 32,
          BC_LIST_HEX32 => 64,
          BC_LIST_HEX64 => 128,
        };
        return str_split(bin2hex($binary), $sz);
        break;

      case BC_NUM:
        return gmp_strval(gmp_init(bin2hex($binary), 16), 10);
        break;

      // case BC_STR:
      //   return gzdecode($binary);
      //   break;

      case BC_NULL:
        return null;
        break;

      default:
        return $binary;
    }
  }

  protected function packType(mixed $data, string $type, string $key): string {
    $sz_fmt = static::PK_MAP[static::SZ_MAP[$type]];
    $bin = pack($this->getPackType($type), $data);
    return $type . $key . pack($sz_fmt, strlen($bin)) . $bin;
  }

  protected function unpackType(string $binary, string $type): mixed {
    return unpack($this->getPackType($type), $binary)[1];
  }

  protected function getKeyType(string $key): string {
    return ($this->config[$key] ?? BC_RAW);
  }

  protected function encodeKeyName(string $key): string {
    return hex2bin(str_pad(dechex(($this->key_map[$key] ?? -1) + 1), 2, '0', STR_PAD_LEFT));
  }

  protected function decodeKeyName(string $bin): ?string {
    return array_search(hexdec(bin2hex($bin)) - 1, $this->key_map);
  }

  protected function getDataType(mixed $data): string {
    return match(true) {
      is_int($data) && $data > 0 && $data < 255 => BC_UINT1,
      is_int($data) && $data > -128 && $data < 127 => BC_INT1,
      is_int($data) && $data > 0 && $data < 65535 => BC_UINT2,
      is_int($data) && $data > -32767 && $data < 32768 => BC_INT2,
      is_int($data) => BC_INT8,
      is_float($data) => BC_DOUBLE,
      is_bool($data) => BC_BOOL,
      is_string($data) && is_numeric($data) && $data[0] !== '0' => BC_NUM,
      is_string($data) => BC_STR,
      is_array($data) && key($data) => BC_MAP,
      is_array($data) => BC_LIST,
      $data === null => BC_NULL,
      default => BC_RAW,
    };
  }

  protected function getPackType(string $type): string {
    return match($type) {
      BC_HEX => 'h',
      BC_CHAR => 'c',
      BC_UCHAR => 'C',
      BC_INT1 => 'c',
      BC_INT2 => 's',
      BC_UINT1 => 'C',
      BC_UINT2 => 'n',
      BC_INT4 => 'l',
      BC_UINT4 => 'N',
      BC_INT8 => 'q',
      BC_UINT8 => 'J',
      BC_FLOAT => 'G',
      BC_DOUBLE => 'E',
      BC_LIST_UINT1 => 'C*',
      BC_LIST_UINT2 => 'n*',
      BC_LIST_UINT4 => 'N*',
      BC_LIST_UINT8 => 'J*',
      BC_LIST_INT1 => 'c*',
      BC_LIST_INT2 => 's*',
      BC_LIST_INT4 => 'l*',
      BC_LIST_INT8 => 'q*',
      default => 'a*',
    };
  }
}