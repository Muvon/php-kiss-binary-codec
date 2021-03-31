<?php
use Muvon\KISS\BinaryCodec;
use PHPUnit\Framework\TestCase;

class BinaryCodecTest extends TestCase {
  public function setUp(): void {
    parent::setUp();
    $this->Codec = BinaryCodec::create([

    ]);
  }

  public function testStringEncode(): void {
    $this->testInputs([
      uniqid(), 'Simple string to be encoded', 'Tratata lalala opaopaopa da',
    ]);
  }

  public function testBoolEncode(): void {
    $this->testInputs([true, false]);
  }

  public function testIntegerEncode(): void {
    $this->testInputs([
      0, 102325, -12312353, 234234235, PHP_INT_MAX
    ]);
  }

  public function testFloatEncode(): void {
    $this->testInputs([
      1.2315324234, lcg_value(), lcg_value(), -lcg_value(), lcg_value() * 100000,
    ]);
  }

  public function testHexEncode(): void {
    $this->testInputs([
      '0000112233', '1f1d0032', '00000000000000000448ee09360b6363d9a32fd623ca2fa299b6bd9236df3461',
      '3da1843c63e2cbb3b4ed4694921ddfbcd911fe5c0b8bd31637a444b9bbf42879',
      '89717217b88b'
    ]);
  }

  public function testIntListEncode(): void {
    $Codec = BinaryCodec::create([
      'list_uint1' => BC_LIST_UINT1,
      'list_uint2' => BC_LIST_UINT2,
      'list_uint4' => BC_LIST_UINT4,
      'list_uint8' => BC_LIST_UINT8,
    ]);
    $this->testInputs([
      ['list_uint1' => range(1, 100)],
      ['list_uint2' => range(2 ** 16 - 100, 2 ** 16 - 1)],
      ['list_uint4' => range(2 ** 32 - 100, 2 ** 32 - 1)],
      ['list_uint8' => [2 ** 48, 2 ** 49, 2 ** 50, 2 ** 51, 2 ** 52, 2 ** 56, 2 ** 60 - mt_rand(1, 100)]],
    ], $Codec);
  }

  public function testNullEncode(): void {
    $this->testInputs([null, [null, null, null, 2]]);
  }

  public function testListEncode(): void {
    $this->testInputs([
      range(0, 100),
      ['hello', 123, true, null],
      [[1, 2 ,3 ], range(0, 10), [true, true, false]],
    ]);
  }

  public function testMapEncode(): void {
    $Codec = BinaryCodec::create([
      'key' => BC_UINT2,
      'value' => BC_STR,
      'numeric' => BC_NUM,
      'list' => BC_LIST,
    ]);

    $this->testInputs([
      ['key' => mt_rand(1, 10000), 'value' => uniqid()],
      ['numeric' => '3492347023702934720397402394732', 'list' => range(1, 100)]
    ], $Codec);
  }

  protected function testInputs(array $inputs, ?BinaryCodec $Codec = null): void {
    if (!$Codec) {
      $Codec = $this->Codec;
    }
    foreach ($inputs as $input) {
      $packed = $Codec->pack($input);
      $this->assertNotEquals($input, $packed);
      $this->assertEquals($input, $Codec->unpack($packed));
    }
  }
}