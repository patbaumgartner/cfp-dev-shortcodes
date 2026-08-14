<?php
/**
 * CFP.DEV shortcodes
 *
 * Fixture data shaped like real CFP.DEV API responses.
 *
 * The set deliberately includes awkward cases the plugin must survive:
 * a non-ASCII speaker name (slug transliteration), a speaker with no company
 * or bio (nullable fields), a talk with no time slot, and an image URL that
 * tries to break out of a CSS `url()` value.
 *
 * @package CFP.DEV
 */

declare(strict_types=1);

namespace CfpDev\Tests;

final class Fixtures {

	public const EVENT_TIMEZONE = 'Europe/Brussels';

	/** URL crafted to escape an unquoted CSS `url(...)` value. */
	public const HOSTILE_IMAGE_URL = 'https://cdn.test/a.jpg);background:red;x:url(b.jpg';

	public static function event(): array {
		return [
			'id'       => 42,
			'name'     => 'Devoxx Belgium 2025',
			'timezone' => self::EVENT_TIMEZONE,
			'fromDate' => '2025-10-06T07:00:00Z',
			'toDate'   => '2025-10-08T17:00:00Z',
		];
	}

	public static function rooms(): array {
		return [
			[
				'id'   => 1,
				'name' => 'Room 4',
			],
			[
				'id'   => 2,
				'name' => 'Room 5',
			],
		];
	}

	public static function tracks(): array {
		return [
			[
				'id'          => 10,
				'name'        => 'Java',
				'description' => '<p>All things Java.</p>',
				'imageURL'    => 'https://cdn.test/track-java.png',
			],
			[
				'id'          => 11,
				'name'        => 'Architecture',
				'description' => '',
				'imageURL'    => 'https://cdn.test/track-arch.png',
			],
		];
	}

	public static function sessionTypes(): array {
		return [
			[
				'id'          => 20,
				'name'        => 'Conference',
				'description' => 'A 50 minute talk.',
				'pause'       => false,
				'duration'    => 50,
			],
			[
				'id'          => 21,
				'name'        => 'Coffee Break',
				'description' => '',
				'pause'       => true,
				'duration'    => 20,
			],
			[
				'id'          => 22,
				'name'        => 'Conference',
				'description' => 'Duplicate display name on purpose.',
				'pause'       => false,
				'duration'    => 50,
			],
		];
	}

	public static function speakers(): array {
		return [
			[
				'id'        => 100,
				'firstName' => 'Jane',
				'lastName'  => 'Doe',
				'company'   => 'Acme',
				'imageUrl'  => 'https://cdn.test/jane.jpg',
			],
			[
				'id'        => 101,
				'firstName' => 'Ilya',
				'lastName'  => 'Šumailov',
				'company'   => null,
				'imageUrl'  => self::HOSTILE_IMAGE_URL,
			],
		];
	}

	public static function speakerDetail( int $id ): array {
		if ( 101 === $id ) {
			return [
				'id'               => 101,
				'firstName'        => 'Ilya',
				'lastName'         => 'Šumailov',
				'company'          => null,
				'bio'              => null,
				'imageUrl'         => self::HOSTILE_IMAGE_URL,
				'twitterHandle'    => null,
				'linkedInUsername' => null,
				'blueskyUsername'  => null,
				'mastodonUsername' => null,
				'proposals'        => [],
			];
		}

		return [
			'id'               => 100,
			'firstName'        => 'Jane',
			'lastName'         => 'Doe',
			'company'          => 'Acme',
			'bio'              => '<p>Jane builds <strong>things</strong>.</p>',
			'imageUrl'         => 'https://cdn.test/jane.jpg',
			'twitterHandle'    => 'janedoe',
			'linkedInUsername' => 'janedoe',
			'blueskyUsername'  => 'jane.bsky.social',
			'mastodonUsername' => 'https://mastodon.social/@janedoe',
			'proposals'        => [
				[
					'id'            => 200,
					'title'         => 'Modern Java in Practice',
					'description'   => '<p>A talk.</p><p><br></p>',
					'audienceLevel' => 'INTERMEDIATE',
					'videoURL'      => 'https://www.youtube.com/embed/abc123',
					'track'         => [
						'id'       => 10,
						'name'     => 'Java',
						'imageURL' => 'https://cdn.test/track-java.png',
					],
					'sessionType'   => [
						'id'   => 20,
						'name' => 'Conference',
					],
					'keywords'      => [ [ 'name' => 'java' ], [ 'name' => 'jvm' ] ],
				],
			],
		];
	}

	public static function talks(): array {
		return [
			[
				'id'            => 200,
				'title'         => 'Modern Java in Practice',
				'audienceLevel' => 'INTERMEDIATE',
				'trackImageURL' => 'https://cdn.test/track-java.png',
				'track'         => [
					'id'       => 10,
					'name'     => 'Java',
					'imageURL' => 'https://cdn.test/track-java.png',
				],
				'sessionType'   => [
					'id'   => 20,
					'name' => 'Conference',
				],
				'speakers'      => [
					[
						'id'        => 100,
						'firstName' => 'Jane',
						'lastName'  => 'Doe',
						'company'   => 'Acme',
						'imageUrl'  => 'https://cdn.test/jane.jpg',
					],
				],
			],
			[
				'id'            => 201,
				'title'         => 'Architecture Without Tears',
				'audienceLevel' => 'BEGINNER',
				'trackImageURL' => 'https://cdn.test/track-arch.png',
				'track'         => [
					'id'       => 11,
					'name'     => 'Architecture',
					'imageURL' => 'https://cdn.test/track-arch.png',
				],
				'sessionType'   => [
					'id'   => 20,
					'name' => 'Conference',
				],
				'speakers'      => [],
			],
		];
	}

	public static function talkDetail( int $id ): array {
		if ( 201 === $id ) {
			return [
				'id'              => 201,
				'title'           => 'Architecture Without Tears',
				'description'     => '<p>No slot scheduled yet.</p>',
				'audienceLevel'   => 'BEGINNER',
				'trackId'         => 11,
				'trackName'       => 'Architecture',
				'trackImageURL'   => 'https://cdn.test/track-arch.png',
				'sessionTypeId'   => 20,
				'sessionTypeName' => 'Conference',
				'tags'            => [],
				'timeSlots'       => [],
				'speakers'        => [],
			];
		}

		return [
			'id'              => 200,
			'title'           => 'Modern Java in Practice',
			'description'     => '<p>A talk about <em>Java</em>.</p>',
			'audienceLevel'   => 'INTERMEDIATE',
			'trackId'         => 10,
			'trackName'       => 'Java',
			'trackImageURL'   => 'https://cdn.test/track-java.png',
			'sessionTypeId'   => 20,
			'sessionTypeName' => 'Conference',
			'videoURL'        => 'https://www.youtube.com/embed/abc123',
			'podcastURL'      => 'https://open.spotify.com/embed/episode/xyz',
			'tags'            => [ [ 'name' => 'java' ] ],
			'timeSlots'       => [
				[
					'fromDate' => '2025-10-06T08:30:00Z',
					'toDate'   => '2025-10-06T09:20:00Z',
					'timezone' => self::EVENT_TIMEZONE,
					'roomName' => 'Room 4',
				],
			],
			'speakers'        => [
				[
					'id'        => 100,
					'firstName' => 'Jane',
					'lastName'  => 'Doe',
					'company'   => 'Acme',
					'bio'       => '<p>Jane builds things.</p>',
					'imageUrl'  => 'https://cdn.test/jane.jpg',
				],
			],
		];
	}

	/**
	 * A talk as the API returns it when every optional field is omitted:
	 * no speakers array, no track image, no audience level, no description.
	 */
	public static function sparseTalk(): array {
		return [
			'id'    => 202,
			'title' => 'Bare Minimum Talk',
		];
	}

	/** Time slots for one conference day (`public/schedules/{Day}`). */
	public static function daySchedule(): array {
		return [
			[
				'fromDate' => '2025-10-06T07:00:00Z',
				'toDate'   => '2025-10-06T07:50:00Z',
			],
			[
				'fromDate' => '2025-10-06T08:30:00Z',
				'toDate'   => '2025-10-06T09:20:00Z',
			],
		];
	}

	/** Sessions for one room on one day (`public/schedules/{Day}/{roomId}`). */
	public static function roomSchedule(): array {
		return [
			[
				'fromDate'    => '2025-10-06T08:30:00Z',
				'toDate'      => '2025-10-06T09:20:00Z',
				'overflow'    => false,
				'room'        => [ 'name' => 'Room 4' ],
				'sessionType' => [
					'name'     => 'Conference',
					'duration' => 50,
				],
				'proposal'    => [
					'id'              => 200,
					'title'           => 'Modern Java in Practice',
					'totalFavourites' => 12,
					'track'           => [ 'imageURL' => 'https://cdn.test/track-java.png' ],
					'speakers'        => [
						[
							'firstName' => 'Jane',
							'lastName'  => 'Doe',
						],
					],
				],
			],
		];
	}
}
