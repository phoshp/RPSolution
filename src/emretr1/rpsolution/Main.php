<?php

declare(strict_types=1);

namespace emretr1\rpsolution;

use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\ResourcePackChunkDataPacket;
use pocketmine\network\mcpe\protocol\ResourcePackChunkRequestPacket;
use pocketmine\network\mcpe\protocol\ResourcePackClientResponsePacket;
use pocketmine\network\mcpe\protocol\ResourcePackDataInfoPacket;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\resourcepacks\ResourcePack;
use pocketmine\scheduler\ClosureTask;

class Main extends PluginBase implements Listener {
	/** @var PackSendEntry[] */
	public static $packSendQueue = [];

	protected function onEnable(): void {
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function (int $currentTick): void {
			foreach (self::$packSendQueue as $entry) {
				$entry->tick($currentTick);
			}
		}), 0);
	}

	public function getRpChunkSize(): int {
		return (int) $this->getConfig()->get("rp-chunk-size", 524288);
	}

	public function getRpChunkSendInterval(): int {
		return (int) $this->getConfig()->get("rp-chunk-send-interval", 30);
	}

	public function onPacketReceive(DataPacketReceiveEvent $event) {
		$player = $event->getPlayer();
		$packet = $event->getPacket();
		if ($packet instanceof ResourcePackClientResponsePacket) {
			if ($packet->status === ResourcePackClientResponsePacket::STATUS_SEND_PACKS) {
				$event->setCancelled(true);

				$manager = $this->getServer()->getResourcePackManager();

				self::$packSendQueue[$player->getName()] = $entry = new PackSendEntry($player);
				$entry->setSendInterval($this->getRpChunkSendInterval());

				foreach ($packet->packIds as $uuid) {
					//dirty hack for mojang's dirty hack for versions
					$splitPos = strpos($uuid, "_");
					if ($splitPos !== false) {
						$uuid = substr($uuid, 0, $splitPos);
					}

					$pack = $manager->getPackById($uuid);
					if (!($pack instanceof ResourcePack)) {
						//Client requested a resource pack but we don't have it available on the server
						$player->close("", "disconnectionScreen.resourcePack", true);
						$this->getServer()->getLogger()->debug("Got a resource pack request for unknown pack with UUID " . $uuid . ", available packs: " . implode(", ", $manager->getPackIdList()));

						return false;
					}

					$pk = new ResourcePackDataInfoPacket();
					$pk->packId = $pack->getPackId();
					$pk->maxChunkSize = $this->getRpChunkSize();
					$pk->chunkCount = (int) ceil($pack->getPackSize() / $pk->maxChunkSize);
					$pk->compressedPackSize = $pack->getPackSize();
					$pk->sha256 = $pack->getSha256();
					$player->sendDataPacket($pk);

					for ($i = 0; $i < $pk->chunkCount; $i++) {
						$pk2 = new ResourcePackChunkDataPacket();
						$pk2->packId = $pack->getPackId();
						$pk2->chunkIndex = $i;
						$pk2->data = $pack->getPackChunk($pk->maxChunkSize * $i, $pk->maxChunkSize);
						$pk2->progress = ($pk->maxChunkSize * $i);

						$entry->addPacket($pk2);
					}
				}
			}
		} elseif ($packet instanceof ResourcePackChunkRequestPacket) {
			$event->setCancelled(true); // dont rely on client
		}
	}
}

class PackSendEntry {
	/** @var DataPacket[] */
	protected $packets = [];
	/** @var int */
	protected $sendInterval = 30;
	/** @var Player */
	protected $player;

	public function __construct(Player $player) {
		$this->player = $player;
	}

	public function addPacket(DataPacket $packet): void {
		$this->packets[] = $packet;
	}

	public function setSendInterval(int $value): void {
		$this->sendInterval = $value;
	}

	public function tick(int $tick): void {
		if (!$this->player->isConnected()) {
			unset(Main::$packSendQueue[$this->player->getName()]);
			return;
		}

		if (($tick % $this->sendInterval) === 0) {
			if ($next = array_shift($this->packets)) {
				$this->player->sendDataPacket($next);
			} else {
				unset(Main::$packSendQueue[$this->player->getName()]);
			}
		}
	}
}
