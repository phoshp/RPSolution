<?php

declare(strict_types=1);

namespace emretr1\rpsolution;

use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\ResourcePackChunkDataPacket;
use pocketmine\network\mcpe\protocol\ResourcePackChunkRequestPacket;
use pocketmine\network\mcpe\protocol\ResourcePackClientResponsePacket;
use pocketmine\network\mcpe\protocol\ResourcePackDataInfoPacket;
use pocketmine\network\mcpe\protocol\types\resourcepacks\ResourcePackType;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\resourcepacks\ResourcePack;
use pocketmine\scheduler\ClosureTask;

class Main extends PluginBase implements Listener{
  /** @var PackSendEntry[] */
  public static $packSendQueue = [];

  public function onEnable() : void{
    $this->getServer()->getPluginManager()->registerEvents($this, $this);
    $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void{
      foreach(self::$packSendQueue as $entry){
        $entry->tick();
      }
    }), 0);
  }

  public function getRpChunkSize() : int{
    return (int) $this->getConfig()->get("rp-chunk-size", 524288);
  }

  public function getRpChunkSendInterval() : int{
    return (int) $this->getConfig()->get("rp-chunk-send-interval", 30);
  }

  public function onPacketReceive(DataPacketReceiveEvent $event) : void{
    $player = $event->getOrigin();
    $packet = $event->getPacket();

    if($packet instanceof ResourcePackClientResponsePacket){
      if($packet->status === ResourcePackClientResponsePacket::STATUS_SEND_PACKS){
        $event->cancel();

        $manager = $this->getServer()->getResourcePackManager();

        self::$packSendQueue[$player->getDisplayName()] = $entry = new PackSendEntry($event->getOrigin());
        $entry->setSendInterval($this->getRpChunkSendInterval());

        foreach($packet->packIds as $uuid){
          //dirty hack for mojang's dirty hack for versions
          $splitPos = strpos($uuid, "_");
          if($splitPos !== false){
            $uuid = substr($uuid, 0, $splitPos);
          }

          $pack = $manager->getPackById($uuid);
          if(!($pack instanceof ResourcePack)){
            //Client requested a resource pack but we don't have it available on the server
            $player->onClientDisconnect("disconnectionScreen.resourcePack");
            $this->getServer()->getLogger()->debug("Got a resource pack request for unknown pack with UUID " . $uuid . ", available packs: " . implode(", ", $manager->getPackIdList()));

            return;
          }

          $pk = ResourcePackDataInfoPacket::create($pack->getPackId(), $this->getRpChunkSize(), (int) ceil($pack->getPackSize() / $this->getRpChunkSize()), $pack->getPackSize(), $pack->getSha256(), false, ResourcePackType::RESOURCES);
          $player->sendDataPacket($pk);

          for($i = 0; $i < $pk->chunkCount; $i++){
            $pk2 = ResourcePackChunkDataPacket::create($pack->getPackId(), $i, ($pk->maxChunkSize * $i), $pack->getPackChunk($pk->maxChunkSize * $i, $pk->maxChunkSize));
            $entry->addPacket($pk2);
          }
        }
      }
    }elseif($packet instanceof ResourcePackChunkRequestPacket){
      $event->cancel(); // dont rely on client
    }
  }
}

class PackSendEntry{
  /** @var DataPacket[] */
  protected $packets = [];
  /** @var int */
  protected $sendInterval = 30;
  /** @var int */
  protected $currentTick = 0;
  /** @var NetworkSession */
  protected $player;

  public function __construct(NetworkSession $player){
    $this->player = $player;
  }

  public function addPacket(DataPacket $packet) : void{
    $this->packets[] = $packet;
  }

  public function setSendInterval(int $value) : void{
    $this->sendInterval = $value;
  }

  public function tick() : void{
    if(!$this->player->isConnected()){
      unset(Main::$packSendQueue[$this->player->getDisplayName()]);
      return;
    }
    $this->currentTick++;

    if(($this->currentTick % $this->sendInterval) === 0){
      if($next = array_shift($this->packets)){
        if($next instanceof ClientboundPacket){
          $this->player->sendDataPacket($next);
        }
      }else{
        unset(Main::$packSendQueue[$this->player->getDisplayName()]);
      }
    }
  }
}