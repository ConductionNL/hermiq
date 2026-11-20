<?php

/**
 * An in-memory IMemcache for unit tests.
 *
 * One instance stands for the instance's distributed cache: hand the same
 * object to two claim stores and they behave like two PHP processes sharing
 * one Redis. `expire()` ends a key's TTL early, which is how a test stands in
 * for "the claim's TTL ran out".
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Support;

use OCP\IMemcache;

/**
 * Array-backed IMemcache with add/inc/dec semantics.
 */
final class InMemoryMemcache implements IMemcache {

	/**
	 * Stored values by key.
	 *
	 * @var array<string, mixed>
	 */
	public array $values = [];

	/**
	 * The TTL each key was written with.
	 *
	 * @var array<string, int>
	 */
	public array $ttls = [];

	/**
	 * End a key's life, as its TTL running out would.
	 *
	 * @param string $key The key.
	 *
	 * @return void
	 */
	public function expire(string $key): void {
		unset($this->values[$key], $this->ttls[$key]);
	}//end expire()

	/** {@inheritDoc} */
	public function get(string $key) {
		return ($this->values[$key] ?? null);
	}//end get()

	/** {@inheritDoc} */
	public function set(string $key, $value, int $ttl = 0) {
		$this->values[$key] = $value;
		$this->ttls[$key] = $ttl;
		return true;
	}//end set()

	/** {@inheritDoc} */
	public function hasKey(string $key) {
		return array_key_exists($key, $this->values);
	}//end hasKey()

	/** {@inheritDoc} */
	public function remove(string $key) {
		$this->expire(key: $key);
		return true;
	}//end remove()

	/** {@inheritDoc} */
	public function clear(string $prefix = '') {
		foreach (array_keys($this->values) as $key) {
			if (str_starts_with($key, $prefix) === true) {
				$this->expire(key: $key);
			}
		}

		return true;
	}//end clear()

	/** {@inheritDoc} */
	public function add($key, $value, $ttl = 0) {
		if ($this->hasKey(key: $key) === true) {
			return false;
		}

		return $this->set(key: $key, value: $value, ttl: $ttl);
	}//end add()

	/** {@inheritDoc} */
	public function inc($key, $step = 1) {
		$this->values[$key] = ((int)($this->values[$key] ?? 0) + $step);
		return $this->values[$key];
	}//end inc()

	/** {@inheritDoc} */
	public function dec($key, $step = 1) {
		$this->values[$key] = ((int)($this->values[$key] ?? 0) - $step);
		return $this->values[$key];
	}//end dec()

	/** {@inheritDoc} */
	public function cas($key, $old, $new) {
		if ($this->get(key: $key) !== $old) {
			return false;
		}

		return $this->set(key: $key, value: $new);
	}//end cas()

	/** {@inheritDoc} */
	public function cad($key, $old) {
		if ($this->get(key: $key) !== $old) {
			return false;
		}

		return $this->remove(key: $key);
	}//end cad()

	/** {@inheritDoc} */
	public function ncad(string $key, mixed $old): bool {
		if ($this->get(key: $key) === $old) {
			return false;
		}

		return $this->remove(key: $key);
	}//end ncad()

	/** {@inheritDoc} */
	public static function isAvailable(): bool {
		return true;
	}//end isAvailable()
}//end class
