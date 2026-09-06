<?php

namespace MediaWiki\Extension\NexaBoard\Tests\Unit;

use MediaWiki\Extension\NexaBoard\AvatarHelper;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\NexaBoard\AvatarHelper
 */
class AvatarHelperTest extends MediaWikiUnitTestCase {

	public function testGenerateColorIsDeterministic(): void {
		$this->assertSame(
			AvatarHelper::generateColor( 'Alice' ),
			AvatarHelper::generateColor( 'Alice' ),
			'The same username must always map to the same colour'
		);
	}

	/**
	 * @dataProvider provideNames
	 */
	public function testGenerateColorReturnsPaletteHex( string $name ): void {
		$color = AvatarHelper::generateColor( $name );
		$this->assertMatchesRegularExpression(
			'/^#[0-9a-f]{6}$/',
			$color,
			"Colour for '$name' should be a 6-digit hex string"
		);
	}

	public static function provideNames(): array {
		return [
			'ascii'       => [ 'Alice' ],
			'ip'          => [ '127.0.0.1' ],
			'unicode'     => [ 'Ævar Arnfjörð' ],
			'empty'       => [ '' ],
			'punctuation' => [ 'A. User (talk)' ],
		];
	}

	public function testGenerateColorVariesAcrossNames(): void {
		$colors = array_map(
			[ AvatarHelper::class, 'generateColor' ],
			[ 'Alice', 'Bob', 'Carol', 'Dave', 'Erin', 'Frank', 'Grace', 'Heidi' ]
		);
		$this->assertGreaterThan(
			1,
			count( array_unique( $colors ) ),
			'Different usernames should not all collapse to a single colour'
		);
	}
}
