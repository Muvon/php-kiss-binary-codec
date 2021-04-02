<?php
namespace Muvon\KISS;

// Max \x06
define('BC_BOOL',   "\x01");
define('BC_LIST',   "\x02");
define('BC_KEY',    "\x03");
define('BC_NUM',    "\x04");
define('BC_NULL',   "\x05");
define('BC_HASH',   "\x06");

final class BinaryCodec {
  protected array $key_map;
  protected int $key_idx = 0;
  protected array $format;

  protected final function __construct() {}

  public static function create(): self {
    return new static;
  }

  public function pack(array $data): string {
    $this->format = [];
    $this->key_idx = 0;
    $this->key_map = [];

    $parts = [];
    $this->format[] = (key($data) === 0 ? BC_LIST : BC_HASH). 'N';
    $parts = [sizeof($data)];

    array_push($parts, ...$this->encode($data));
    $format_str = implode('/', $this->format);
    $flow = gzencode($format_str . "\0" . implode("/", array_keys($this->key_map)));

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
          if (!isset($this->key_map[$k])) {
            $this->key_map[$k] = $this->key_idx++;
          }
          $this->format[] = BC_KEY . 'C';
          $parts[] = $this->key_map[$k];
        }

        // Can be list or hash array
        if (is_array($v)) {
          $sz = sizeof($v);
          $this->format[] = (key($v) === 0 || $sz === 0)
            ? BC_LIST . 'N'
            : BC_HASH . 'N'
          ;
          $parts[] = $sz;
        }

        array_push($parts, ...$this->encode($v));
      }

      return $parts;
    } else {
      $this->format[] = $this->getDataFormat($data);
      return [$data];
    }
  }

  public function unpack(string $binary): array {
    $meta_len = hexdec(bin2hex($binary[0] . $binary[1] . $binary[2] . $binary[3]));
    return $this->decode(gzdecode(substr($binary, 4, $meta_len)), substr($binary, 4 + $meta_len));
  }

  protected function decode(string $meta, string $binary): mixed {
    $i = 0;
    $keys = [];
    $format = '';
    foreach (explode('/', strtok($meta, "\0")) as $f) {
      $key = 'n' . ($i++);
      $prefix = match ($f[0]) {
        BC_NULL, BC_NUM, BC_BOOL, BC_LIST, BC_KEY, BC_HASH => substr($f, 1),
        default => $f,
      };

      if ($prefix !== $f) {
        $keys[$key] = $f[0];
      }

      $format .= $prefix . $key . '/';
    }
    $format = rtrim($format, '/');
    $key_map = explode('/', strtok("\0"));
    $result = null;
    $ref = &$result;
    $path = [];
    $ns = [];
    $i = 0;
    $n = 0;

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
      }

      // Write data to ref logic
      if (isset($ref)) {
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
        case is_string($data) && is_numeric($data) && $data[0] !== '0' && trim($data, '0..9') === '':
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
}

function binary_pack(array $data): string {
  return BinaryCodec::create()->pack($data);
}

function binary_unpack(string $binary): array {
  return BinaryCodec::create()->unpack($binary);
}