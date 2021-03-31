# php-kiss-binary-codec
Binary codec for encoding multiple structures with predefined config

## Encoder flow

1 byte - binary type
1 byte - binary key
2 byte - size of binary encoded data


## Binary types

00 binary
01 integer
02 float
03 bool
04 list
05 map