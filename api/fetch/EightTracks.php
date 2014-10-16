<?php

include_once("include/Database.php");
include_once("include/Curl.php");

class EightTracks extends Database {

  // Mix info.
  private $apiKey = "3b7b9c79a600f667fe2113ff91183149779a74b8";
  private $url;
  private $mixId;
  private $playToken;
  private $trackNumber;
  private $tracksCount;

  // Output array.
  private $outputArray = array();

  /**
   * Get mix info from URL.
   */
  private function getMixInfo() {
    $curl = new Curl();
    $mixArray = $curl->returnTransfer($this->url.".jsonp?api_key=".$this->apiKey."&api_version=3");

    if ($mixArray["errors"]) {
      $this->output->error("8tracks said: ".$errors);
    }

    $this->mixId = $mixArray["mix"]["id"];

    if (empty($this->mixId)) {
      $this->output->error("Invalid URL: ".$this->url);
    }

    $this->tracksCount = $mixArray["mix"]["tracks_count"];
    $this->outputArray["mix"] = $mixArray["mix"];
  }

  /**
   * Get existing songs from database.
   * @return boolean
   */
  private function getSongsFromDb() {
    $playlistSongs = $this->query("SELECT songId FROM 8tracks_playlists_songs WHERE mixId='".$this->mixId."' AND trackNumber >= ".$this->trackNumber." ORDER BY trackNumber");

    if (mysqli_num_rows($playlistSongs) > 0) {
      while ($playlistSong = mysqli_fetch_assoc($playlistSongs)) {
        $songs = $this->query("SELECT * FROM 8tracks_songs WHERE songId='".$playlistSong["songId"]."'");
        while ($song = mysqli_fetch_assoc($songs)) {
          $this->outputArray["songs"][] = $song;
        }
      }
      return 0;
    }

    return 1;
  }

  /**
   * Get the next song in the playlist.
   */
  private function nextSong() {
    $retries = 0;

    do {
      $retries++;

      $curl = new Curl();
      $songArray = $curl->returnTransfer("http://8tracks.com/sets/".$this->playToken."/next?format=jsonh&mix_id=".$this->mixId."&api_version=2");

      $status = $songArray["status"];

      if ($retries > 1) {
        if (preg_match('/(403)/', $status)) {
          $this->output->error("8tracks denied our request.");
        } else {
          $this->output->error("8tracks made a boo boo.");
        } 
      }
    } while (!preg_match('/(200)/', $status));

    $songId = $songArray["set"]["track"]["id"];

    if (isset($songId)) {
      $title = addslashes($songArray["set"]["track"]["name"]);
      $artist = addslashes($songArray["set"]["track"]["performer"]);
      $album = addslashes($songArray["set"]["track"]["release_name"]);
      $duration = $songArray["set"]["track"]["play_duration"];
      $songUrl = $songArray["set"]["track"]["url"];

      // If $songId isn't in the table, add a new row.
      if (mysqli_num_rows($this->query("SELECT mixId FROM 8tracks_playlists_songs WHERE mixId='$this->mixId' AND songId='$songId' LIMIT 1")) == 0) {
        $this->query("INSERT INTO 8tracks_playlists_songs (mixId, songId, trackNumber) VALUES ('$this->mixId', '$songId', '$this->trackNumber')");

        if (mysqli_num_rows($this->query("SELECT songId FROM 8tracks_songs WHERE songId='$songId' LIMIT 1")) == 0) {
          $this->query("INSERT INTO 8tracks_songs (songId, title, artist, album, duration, songUrl) VALUES ('$songId', '$title', '$artist', '$album', '$duration', '$songUrl')");
        }
      }
    } else {
      $this->output->error("That's all we could find.");
    }
  }

  /**
   * Get the playlist.
   * @param string $url
   * @param string $mixId
   * @param string $playToken
   * @param int $trackNumber
   * @return array
   */
  function getPlaylist($url, $mixId, $playToken, $trackNumber = 0) {
    ignore_user_abort(true);

    $this->url = $url;
    $this->mixId = $mixId;
    $this->playToken = $playToken;
    $this->trackNumber = $trackNumber;

    // If no $mixId then fetch $mixId and $tracksCount.
    if (empty($mixId)) {
      $this->getMixInfo();
    }

    if (mysqli_num_rows($this->query("SELECT mixId FROM 8tracks_playlists WHERE mixId=".$this->mixId." LIMIT 1")) < 1) {
      // If mix isn't in database.

      $this->playToken = rand();
      $this->query("INSERT INTO 8tracks_playlists (mixId, tracksCount, playToken) VALUES ('$this->mixId', '$this->tracksCount', '$this->playToken')");
      $this->nextSong();
    } else if (empty($playToken)) {
      /* If mix has already been entered and this is
       * the clients first time requesting. */

      $row = mysqli_fetch_array($this->query("SELECT tracksCount, playToken FROM 8tracks_playlists WHERE mixId='$mixId' LIMIT 1"));
      $this->playToken = $row["playToken"];
    } else if ($this->getSongsFromDb()) {
      // If mix is in database but doesn't have songs.

      $this->nextSong();
    }

    $this->getSongsFromDb();

    $this->output->successWithData($this->outputArray);
  }
}
