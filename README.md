# php-kiss-binary-codec
Binary codec for encoding multiple structures with predefined config

## Encoder flow

Each encoded field has next structure

1 byte - binary type
1 byte - binary key
1-4 byte - size of binary encoded data depends on binary type
x byte - body

## Supported binary types

Binary types defined as contants with prefix BC_*

Each binary type has own max size definition that prepended to binary result of packed data.

Here is full list of contants

| Constant | Max size | Info |
|-|-|
| BC_RAW | 4 bytes | Raw data representation |
| BC_BOOL | 1 byte | Boolean can be true or false only |
| BC_INT1 | 1 byte | 8 bit signed integer |
| BC_UINT1 | 1 byte | 8 bit unsigned integer |
| BC_INT2 | 1 byte | 16 bit signed integer |
| BC_UINT2 | 1 byte | 16 bit unsigned integer |
| BC_INT4 | 1 byte | 32 bit signed integer |
| BC_UINT4 | 1 byte | 32 bit unsigned integer |
| BC_INT8 | 1 byte | 64 bit signed integer |
| BC_UINT8 | 1 byte | 64 bit unsigned integer |
| BC_FLOAT | 2 bytes | Float |
| BC_DOUBLE | 2 bytes | Actually same as float in PHP |
| BC_HEX | 4 bytes | Hex encoded value |
| BC_CHAR | 1 byte | 8 bit signed char |
| BC_UCHAR | 1 byte | 8 bit unsigned char |
| BC_STR | 4 bytes | Just string data |
| BC_LIST | 4 bytes | Simple array that represent list with indexed keys |
| BC_MAP | 4 bytes | Hash-map that is simple array but with associative keys |
| BC_NUM | 1 byte | Actually string representation of real big integer |
| BC_NULL | 1 byte | NULL value |

## Binary keys

Binary key are defined by configuration passed in codec and cannot exceed amount of 256 types total.

## Size of binary

Size is presented in bytes for encoded data.

## Test coverage

- [x] String encode
- [x] Bool encode
- [x] Integer encode
- [x] Float encode
- [x] Hex encode
- [x] Null encode
- [x] List encode
- [x] Map encode