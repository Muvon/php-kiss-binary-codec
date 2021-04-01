<?php
namespace Muvon\KISS;

use Error;

// Max \x06
define('BC_BOOL',   "\x01");
define('BC_LIST',   "\x02");
define('BC_KEY',    "\x03");
define('BC_NUM',    "\x04");
define('BC_NULL',   "\x05");
define('BC_HASH',   "\x06");
final class BinaryCodec {
  protected array $key_map;
  protected array $format;

  protected final function __construct() {}

  public static function create(): self {
    return new static;
  }

  public function pack(array $data): string {
    $this->format = [];
    $this->key_map = [];

    $parts = [];
    $this->format[] = (key($data) === 0 ? BC_LIST : BC_HASH). 'N';
    $parts = [sizeof($data)];

    array_push($parts, ...$this->encode($data));
    $format_str = implode('/', $this->format);
    $flow = gzencode($format_str . "\0" . implode("/", $this->key_map));
    return pack(
      'Na*' . strtr($format_str, [
        '/' => '', BC_HASH => '', BC_KEY => '',
        BC_LIST => '', BC_BOOL => '', BC_NULL => '',
        BC_NUM => ''
      ]),
      strlen($flow),
      $flow,
      ...$parts
    );
  }

  protected function encode(mixed $data): array {
    if (is_array($data)) {
      $parts = [];
      foreach ($data as $k => $v) {
        // First we do hash map key cuz it can contain list
        if (is_string($k)) {
          if (!in_array($k, $this->key_map)) {
            $this->key_map[] = $k;
            $idx = sizeof($this->key_map) - 1;
          } else {
            $idx = array_search($k, $this->key_map);
          }
          $this->format[] = BC_KEY . 'C';
          array_push($parts, $idx);
        }

        // Can be list or hash array
        if (is_array($v)) {
          $sz = sizeof($v);
          if (key($v) === 0 || $sz === 0) {
            $this->format[] = BC_LIST . 'N';
            array_push($parts, $sz);
          } else {
            $this->format[] = BC_HASH . 'N';
            array_push($parts, $sz);
          }
        }

        array_push($parts, ...$this->encode($v));
      }

      return $parts;
    } else {
      $this->format[] = $this->getDataFormat($data);
      return [$data];
    }
  }

  public function unpack(string $binary): mixed {
    $meta_len = hexdec(bin2hex($binary[0] . $binary[1] . $binary[2] . $binary[3]));
    return $this->decode(gzdecode(substr($binary, 4, $meta_len)), substr($binary, 4 + $meta_len));
  }

  protected function decode(string $meta, string $binary): mixed {
    $i = 0;
    $keys = [];
    $format = strtok($meta, "\0");
    $key_map = explode('/', strtok("\0"));
    $format = implode('/', array_map(
      function ($item) use (&$i, &$keys) {
        $key = 'n' . ($i++);
        $prefix = match ($item[0]) {
          BC_NULL, BC_NUM, BC_BOOL, BC_LIST, BC_KEY, BC_HASH => substr($item, 1),
          default => $item,
        };

        if ($prefix !== $item) {
          $keys[$key] = $item[0];
        }

        return $prefix . $key;
      },
      explode('/', $format)
    ));
    $result = null;
    $ref = &$result;
    $path = [];
    $ns = [];
    $i = 0;
    $n = 0;

    $prev = null;
    foreach (unpack($format, $binary) as $k => &$v) {
      if (isset($keys[$k])) {
        $v = match ($keys[$k]) {
          BC_NULL => null,
          BC_NUM => gmp_strval(gmp_init(bin2hex($v), 16)),
          BC_BOOL => !!$v,
          default => $v,
        };

        if ($keys[$k] === BC_LIST) {
          $ns[] = $v;
          if (!isset($ref)) {
            $ref = [];
            $path[] = '-';
          } else {
            $ref[$i] = [];
            $ref = &$ref[$i];
            $path[] = $i;
          }

          $i = 0;
          continue;
        }

        if ($keys[$k] === BC_HASH) {
          $ns[] = $v;
          if (is_array($ref)) {
            $ref[$i] = [];
            $ref = &$ref[$i];
            $path[] = $i;
            // $i++;
          } else {
            $ref = [];
            $path[] = '-';
          }
          continue;
        }

        if ($keys[$k] === BC_KEY) {
          $h_key = $key_map[$v];
          $ref = &$ref[$h_key];
          $ns[]   = 1;
          $path[] = $h_key;
          continue;
        }

        $prev = &$keys[$k];
      }

      // Write data to ref logic
      if (is_array($ref)) {
        $ref[$i++] = $v;
      } else {
        $ref = $v;
      }

      // Reset ref pointer logic
      $n = &$ns[array_key_last($ns)];
      if (--$n === 0) {
        do {
          array_pop($path);
          array_pop($ns);
          $n = &$ns[array_key_last($ns)];
        } while(--$n === 0);

        $ref = &$result;
        foreach ($path as $p) {
          if ($p === '-') {
            continue;
          }
          $ref = &$ref[$p];
        }

        if ($ref) {
          $i = sizeof($ref);
        }
      }
    }
    return $result;
  }

  protected function getDataFormat(mixed &$data): string {
    $format = match(true) {
      is_int($data) && $data > 0 && $data < 255 => 'C',
      is_int($data) && $data > -128 && $data < 127 => 'c',
      is_int($data) && $data > 0 && $data < 65535 => 'n',
      is_int($data) && $data > -32768 && $data < 32767 => 's',
      is_int($data) && $data > 0 && $data < 4294967295 => 'N',
      is_int($data) && $data > -2147483648 && $data < 2147483647 => 'l',
      is_int($data) && $data > 0 && $data < 4294967295 => 'N',
      is_int($data) && $data > -9223372036854775808 && $data < 9223372036854775807 => 'q',
      is_int($data) && $data > 0 && $data < 18446744073709551615 => 'J',
      is_double($data) => 'E',
      is_float($data) => 'G',
      default => 'a',
    };

    if ($format === 'a') {
      switch (true) {
        case is_string($data) && is_numeric($data) && $data[0] !== '0':
          $val = gmp_strval(gmp_init($data, 10), 16);
          if (strlen($val) % 2 !== 0) {
            $val = '0' . $val;
          }
          $format = BC_NUM . 'a';
          $data = hex2bin($val);
          break;

        case is_string($data) && trim($data, '0..9A..Fa..f') == '':
          $format = 'H';
          break;

        case is_bool($data):
          $format = BC_BOOL . 'C';
          $data = $data ? 1 : 0;
          break;

        case is_null($data):
          $format = BC_NULL . 'C';
          $data = "\x01";
          break;

      }
      $format .= strlen($data);
    }

    return $format;
  }

  // protected function getPackType(string $type): string {
  //   return match($type) {
  //     BC_HEX => 'H*',
  //     BC_CHAR => 'c',
  //     BC_UCHAR => 'C',
  //     BC_INT4 => 'l',
  //     BC_UINT4 => 'N',
  //     BC_INT8 => 'q',
  //     BC_UINT8 => 'J',
  //     BC_FLOAT => 'G',
  //     BC_DOUBLE => 'E',
  //     BC_LIST_UINT1 => 'C*',
  //     BC_LIST_UINT2 => 'n*',
  //     BC_LIST_UINT4 => 'N*',
  //     BC_LIST_UINT8 => 'J*',
  //     BC_LIST_INT1 => 'c*',
  //     BC_LIST_INT2 => 's*',
  //     BC_LIST_INT4 => 'l*',
  //     BC_LIST_INT8 => 'q*',
  //     default => 'a*',
  //   };
  // }
}