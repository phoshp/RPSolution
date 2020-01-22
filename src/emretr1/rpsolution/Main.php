<?php

declare(strict_types=1);

namespace emretr1\rpsolution;

use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\ResourcePackChunkDataPacket;
use pocketmine\network\mcpe\protocol\ResourcePackChunkRequestPacket;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\resourcepacks\ResourcePack;
use pocketmine\scheduler\ClosureTask;

class Main extends PluginBase implements Listener{
	/** @var PackSendEntry[] */
	public static $packSendQueue = [];

	public function onEnable(){
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function (int $currentTick) : void{
			foreach(self::$packSendQueue as $entry){
				$entry->tick();
			}
		}), 0);
	}
	
	public function onQuit(PlayerQuitEvent $event){
		unset(self::$packSendQueue[$event->getPlayer()->getLowerCaseName()]);
	}
	
	public function onPacketReceive(DataPacketReceiveEvent $event){
		$player = $event->getPlayer();
		$packet = $event->getPacket();
		if($packet instanceof ResourcePackChunkRequestPacket){
			$event->setCancelled();
			
			$manager = $this->getServer()->getResourcePackManager();
			$pack = $manager->getPackById($packet->packId);
			if(!($pack instanceof ResourcePack)){
				$player->close("", "disconnectionScreen.resourcePack", true);
				$this->getServer()->getLogger()->debug("Got a resource pack chunk request for unknown pack with UUID " . $packet->packId . ", available packs: " . implode(", ", $manager->getPackIdList()));
				
				return;
			}
			
			$pk = new ResourcePackChunkDataPacket();
			$pk->packId = $packet->packId;
			$pk->chunkIndex = $packet->chunkIndex;
			$pk->data = $pack->getPackChunk(1048576 * $packet->chunkIndex, 1048576);
			$pk->progress = (1048576 * $packet->chunkIndex);
			
			if(!isset(self::$packSendQueue[$name = $player->getLowerCaseName()])){
				self::$packSendQueue[$name] = $entry = new PackSendEntry($player);
			}else{
				$entry = self::$packSendQueue[$name];
			}
			
			$entry->addPacket($pk);
		}
	}
}

class PackSendEntry{
	/** @var DataPacket[] */
	protected $packets = [];
	/** @var int */
	protected $lastPacketSendTime = 0;
	/** @var int */
	protected $sendInterval = 1;
	/** @var Player */
	protected $player;
	
	public function __construct(Player $player){
		$this->player = $player;
	}
	
	public function addPacket(DataPacket $packet) : void{
		$this->packets[] = $packet;
	}
	
	public function tick() : void{
		if((time() - $this->lastPacketSendTime) >= $this->sendInterval){
			if($next = array_shift($this->packets)){
				$this->player->sendDataPacket($next);
				
				$this->lastPacketSendTime = time();
			}
		}
	}
	
	/**
	 * @param int $sendInterval
	 */
	public function setSendInterval(int $sendInterval) : void{
		$this->sendInterval = $sendInterval;
	}
}