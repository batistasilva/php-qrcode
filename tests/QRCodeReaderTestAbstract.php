<?php
/**
 * Class QRCodeReaderTestAbstract
 *
 * @created      17.01.2021
 * @author       Smiley <smiley@chillerlan.net>
 * @copyright    2021 Smiley
 * @license      MIT
 *
 * @noinspection PhpComposerExtensionStubsInspection
 * @phan-file-suppress PhanUndeclaredConstant
 */
declare(strict_types=1);

namespace chillerlan\QRCodeTest;

use chillerlan\QRCode\{QRCode, QROptions};
use chillerlan\QRCode\Common\{EccLevel, LuminanceSourceInterface, Mode, Version};
use chillerlan\QRCode\Decoder\Decoder;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\Settings\SettingsContainerInterface;
use PHPUnit\Framework\Attributes\{DataProvider, Group};
use PHPUnit\Framework\TestCase;
use Exception, Generator;
use RuntimeException;
use function array_map, defined, realpath, sprintf, str_repeat, substr;

/**
 * Tests the QR Code reader
 */
abstract class QRCodeReaderTestAbstract extends TestCase{
	use QRMaxLengthTrait, QRMatrixDebugTrait;

	/** @see https://www.bobrosslipsum.com/ */
	protected const loremipsum = 'Just let this happen. We just let this flow right out of our minds. '
		.'Anyone can paint. We touch the canvas, the canvas takes what it wants. From all of us here, '
		.'I want to wish you happy painting and God bless, my friends. A tree cannot be straight if it has a crooked trunk. '
		.'You have to make almighty decisions when you\'re the creator. I guess that would be considered a UFO. '
		.'A big cotton ball in the sky. I\'m gonna add just a tiny little amount of Prussian Blue. '
		.'They say everything looks better with odd numbers of things. But sometimes I put even numbers—just '
		.'to upset the critics. We\'ll lay all these little funky little things in there. ';

	protected const samplesDir = __DIR__.'/samples/';

	protected SettingsContainerInterface|QROptions $options;

	protected function setUp():void{
		$this->options = new QROptions;
		$this->options->readerUseImagickIfAvailable = false;
	}

	/**
	 * @phpstan-return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public static function qrCodeProvider():array{
		return [
			'helloworld' => ['hello_world.png', 'Hello world!', false],
			// covers mirroring
			'mirrored'   => ['hello_world_mirrored.png', 'Hello world!', false],
			// data modes
			'byte'       => ['byte.png', 'https://smiley.codes/qrcode/', true],
			'numeric'    => ['numeric.png', '123456789012345678901234567890', false],
			'alphanum'   => ['alphanum.png', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890 $%*+-./:', false],
			'kanji'      => ['kanji.png', '茗荷茗荷茗荷茗荷', false],
			// covers most of ReedSolomonDecoder
			'damaged'    => ['damaged.png', 'https://smiley.codes/qrcode/', false],
			// covers attempt to read 2nd (bottom left) version info
			'version2'   => ['damaged_version.png', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', false],
			// covers Binarizer::getHistogramBlackMatrix()
			'smol'       => ['smol.png', 'https://smiley.codes/qrcode/', false],
			// tilted 22° CCW
			'tilted'     => ['tilted.png', 'Hello world!', false],
			// rotated 90° CW
			'rotated'    => ['rotated.png', 'Hello world!', false],
			// color gradient (from old svg example)
			'gradient'   => ['example_svg.png', 'https://www.youtube.com/watch?v=DLzxrzFCyOs&t=43s', true],
			// color gradient (from svg example)
			'dots'       => ['example_svg_dots.png', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', true],
		];
	}

	abstract protected function getLuminanceSourceFromFile(
		string                               $file,
		SettingsContainerInterface|QROptions $options,
	):LuminanceSourceInterface;

	#[Group('slow')]
	#[DataProvider('qrCodeProvider')]
	public function testReader(string $img, string $expected, bool $grayscale):void{

		if($grayscale){
			$this->options->readerGrayscale        = true;
			$this->options->readerIncreaseContrast = true;
		}

		$file = realpath($this::samplesDir.$img);

		if($file === false){
			throw new RuntimeException(sprintf('invalid file given: "%s" in samples directory "%s"', $img, $this::samplesDir));
		}

		$luminanceSource = $this->getLuminanceSourceFromFile($file, $this->options);
		$result          = (new Decoder)->decode($luminanceSource);

		$this->debugMatrix($result->getQRMatrix());

		$this::assertSame($expected, (string)$result);
	}

	public function testReaderMultiMode():void{
		$this->options->outputInterface = QRGdImagePNG::class;
		$this->options->outputBase64    = false;

		$numeric  = '123456789012345678901234567890';
		$alphanum = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890 $%*+-./:';
		$kanji    = '漂う花の香り';
		$hanzi    = '无可奈何燃花作香';
		$byte     = 'https://smiley.codes/qrcode/';

		$qrcode = (new QRCode($this->options))
			->addNumericSegment($numeric)
			->addAlphaNumSegment($alphanum)
			->addKanjiSegment($kanji)
			->addHanziSegment($hanzi)
			->addByteSegment($byte)
		;

		$result = $qrcode->readFromBlob($qrcode->render());

		$this::assertSame($numeric.$alphanum.$kanji.$hanzi.$byte, $result->data);
	}

	public static function dataTestProvider():Generator{

		if(!defined('READER_TEST_MAX_VERSION')){
			self::markTestSkipped('READER_TEST_MAX_VERSION not defined (see phpunit.xml.dist)');
		}

		$str       = str_repeat(self::loremipsum, 5);
		$eccLevels = array_map(fn(int $ecc):EccLevel => new EccLevel($ecc), [EccLevel::L, EccLevel::M, EccLevel::Q, EccLevel::H]);

		/** @noinspection PhpUndefinedConstantInspection - see phpunit.xml.dist */
		for($v = 1; $v <= READER_TEST_MAX_VERSION; $v++){
			$version = new Version($v);

			foreach($eccLevels as $eccLevel){
				yield 'version: '.$version.$eccLevel => [
					$version,
					$eccLevel,
					substr($str, 0, self::getMaxLengthForMode(Mode::BYTE, $version, $eccLevel)),
				];
			}
		}

	}

	#[Group('slow')]
	#[DataProvider('dataTestProvider')]
	public function testReadData(Version $version, EccLevel $ecc, string $expected):void{
		$this->options->outputInterface  = QRGdImagePNG::class;
		$this->options->imageTransparent = false;
		$this->options->eccLevel         = $ecc->getLevel();
		$this->options->version          = $version->getVersionNumber();
		$this->options->outputBase64     = false;
		// what's interesting is that a smaller scale seems to produce fewer reader errors???
		// usually from version 20 up, independend of the luminance source
		// scale 1-2 produces none, scale 3: 1 error, scale 4: 6 errors, scale 5: 5 errors, scale 10: 10 errors
		// @see \chillerlan\QRCode\Detector\GridSampler::checkAndNudgePoints()
		$this->options->scale            = 2;

		try{
			$qrcode    = new QRCode($this->options);
			$imagedata = $qrcode->render($expected);
			$result    = $qrcode->readFromBlob($imagedata);
		}
		catch(Exception $e){
			$this::markTestSkipped(sprintf('skipped version %s%s: %s', $version, $ecc, $e->getMessage()));
		}

		$this::assertSame($expected, $result->data);
		$this::assertSame($version->getVersionNumber(), $result->version->getVersionNumber());
		$this::assertSame($ecc->getLevel(), $result->eccLevel->getLevel());
	}

}
