<?php


// reusable tools
class mp3tools {
 /*
  * shell mp3 tools
  */


  function shell_remove_dir($dir) {
   if (empty($dir)) return;
   if (!self::dir_exists($dir)) return;
   $dir_arg = escapeshellarg($dir);
   shell_exec("rm -R {$dir_arg}");
  }


  function shell_split_mp3_maxsize($src, $dst, $max_mb = 10) {
   $size = 101; // default split size at 30 minutes
   $max_filesize = $max_mb * 1024 * 1024; //  mb file size
   $dst = rtrim($dst, '/');
   $tmp_dir = self::rmkdir($dst .'/mp3split_tmp');
   do { // split and repeat with smaller size until final files are small enough to podcast
       $size--;
       self::shell_split_mp3($src, $tmp_dir, $size);
       $new_count = count(self::list_files($tmp_dir));
       $new_size = self::biggest_filesize($tmp_dir);
       // echo "  * converted file into {$new_count} files with max file size: ". round($new_size / 1024 /1024, 2).
        //   "mb using length of {$size}.0 minutes \n";
   } while ((mp3tools::biggest_filesize($tmp_dir) > $max_filesize) && ($size > 5)); // 5 minutes is too short
   // now copy over files
   $files = self::list_files($tmp_dir);
   foreach ($files as $file) {
     copy($tmp_dir .'/'. $file, $dst .'/'. $file);
     unlink($tmp_dir .'/'. $file);
     chmod($dst .'/'. $file, 0766);
   }
   rmdir($tmp_dir);
  }

  // splits mp3 into target directory and then returns array of files
  // takes src file and dst folder
  function shell_split_mp3($src, $dst, $size=30) {
    $src_arg = escapeshellarg($src);
    $dst_arg = escapeshellarg($dst);
    // set up destination folder
    self::shell_remove_dir($dst); mkdir($dst, 0777, true);
    // split the file into $size minute pieces
    shell_exec("mp3splt -f -t {$size}.0 -a -d {$dst_arg} -o @f-@n -n -q {$src_arg}");
  }

  // pushes WAV file through FFMPEG to relatively high bitrate. This forces WAV out of poorly supported compressed formats
  function shell_clean_wav($src, $dst){
    if (!file_exists($src)) return FALSE;
    $src_arg = escapeshellarg($src);
    $dst_arg = escapeshellarg($dst);
    $cmd = "ffmpeg -i {$src_arg} -ar 44100 -ab 16k {$dst_arg}";
    
//drupal_set_message(">>> $cmd");

    shell_exec($cmd);
    chmod($dst, 0766);
  }

  // pushes WAV file through FFMPEG to relatively high bitrate. This forces WAV out of poorly supported compressed formats
  function shell_transcode_speech($src, $dst){
    if (!file_exists($src)) return FALSE;
    $src_arg = escapeshellarg($src);
    $dst_arg = escapeshellarg($dst);
    $cmd = "lame -V9 --vbr-new -mm --lowpass 10 --highpass 0.08 --resample 24 -q2 -B24 -p --replaygain-accurate --strictly-enforce-ISO {$src_arg} {$dst_arg}";
    shell_exec($cmd);
    chmod($dst, 0766);
  }


  // $tags is a key=>value array of keys: title, artist, album, year, comment, genre, track
  function shell_writeid3($file, $tags) {
    //drupal_set_message("shell_writeid3($file) \n <pre>".print_r($tags,true)."</pre>");
    // verify file exists
    if (!file_exists($file)) {
      //drupal_set_message("File not found: <b>$file</b>", 'warning');
      return FALSE;
    }
    // verify array items found
    // verify id3v2 library found
    // read previous tags
    $old_tags = self::shell_readid3($file);
    $tag_values = array('Title'=>'-t','Artist'=>'-a','Album'=>'-A','Year'=>'-y','Genre'=>'-g','Comment'=>'-c','Track'=>'-T');
    // loop through and make changes, note if dirty
    $changes = array();
    foreach ($tags as $tag=>$value) {
     $tag = ucfirst($tag);
     if (array_key_exists($tag, $tag_values)) {
      if (!isset($old_tags[$tag]) || ($value != $old_tags[$tag])) $changes[$tag] = $value;
     }
    }
    if (!count($changes)) {
      //drupal_set_message("No Changes found. All Tags match existing ones.");
      return TRUE;
    }
    foreach ($changes as $tag=>$value) $cmd[] = $tag_values[$tag].' '.escapeshellarg($value);
    $cmd = 'id3v2 '.implode(' ', $cmd).' '.escapeshellarg($file);
    //echo ">>> $cmd \n";
    //drupal_set_message("id3>>> $cmd");
    shell_exec($cmd);
  }

  // returns array of ID3v1.1 tags as key=>val array. Keys: Title, Artist, Album, Year, Comment, Genre, Track
  // only returns items that are tagged
  function shell_readid3($file) {
    // verify file exists
    if (!file_exists($file)) return FALSE;
    // verify id3v2 library found' ??
    // fetch data block
    ob_start();
     $read_cmd = "id3v2 -l ". escapeshellarg($file);
     $id3data = shell_exec($read_cmd);
    ob_end_clean();
    /* parse into array from this block
  Title  : test 1/4                        Artist:
  Album  : test                            Year:     , Genre: Speech (101)
  Comment:                                 Track: 1 */
    // clean up messiness and get each item onto one line
    $id3data = str_replace('  :', ":", $id3data);
    $id3data = str_replace(' :', ":", $id3data);
    $id3data = str_replace('Artist:', "\nArtist:", $id3data);
    $id3data = str_replace('Year:', "\nYear:", $id3data);
    $id3data = str_replace('Track:', "\nTrack:", $id3data);
    $id3data = str_replace('Genre:', "\nGenre:", $id3data);
    $id3data = explode("\n",$id3data);
    foreach ($id3data as $line) {
     if ((preg_match('/^(.+):.*/', $line, $tag)) && (in_array($tag[1], array('Title','Artist','Album','Year','Genre','Comment','Track')))) {
      $tag = $tag[1];
      $value = trim(substr($line, strlen($tag)+1));
      if ($tag=='Genre') $value =  trim(preg_replace('/ \(\d+\)/', '', $value)); // strip out genre number part
      if ($tag=='Year') $value = (int)($value)>0 && (int)($value)<3000?(int)$value:''; // force year to be a number or blank
      $result[$tag] = $value;
     }
    }
   // done
   return $result;
  }

  // list of $files with full path, target file with full path
  function shell_mp3concat($files, $target) {
    $target_arg = escapeshellarg($target);
    $tmp_file = self::unique_filename(dirname($target) .'/merged_tmp.mp3'); $tmp_arg = escapeshellarg($tmp_file);
    foreach ($files as $file) $args[] = escapeshellarg($file);
    //$mrg_cmd = "mp3wrap {$tmp_arg} ". implode(' ',$args); // mp3wrap tmp.mp3 1.mp3 2.mp3 3.mp3
    $mrg_cmd = "cat ". implode(' ',$args) ." > {$tmp_arg}"; // cat 1.mp3 2.mp3 3.mp3 > tmp.mp3
    shell_exec($mrg_cmd);

    // fix duration information in a single-encoded wihtout re-transcoding
    // $pi = pathinfo($target); $tmp_file = $pi['filename'] .'_MP3WRAP.mp3'; $tmp_arg = escapeshellarg($tmp_file);
    $fix_cmd = "ffmpeg -i {$tmp_arg}  -acodec copy {$target_arg} && rm {$tmp_arg}"; // ffmpeg -i tmp_MP3WRAP.mp3 -acodec copy all.mp3 && rm tmp_MP3WRAP.mp3
    // alt: vbrfix -ri1 -ri2 -lameinfo tmp_MP3WRAP.mp3 all.mp3 && rm tmp_MP3WRAP.mp3
    shell_exec($fix_cmd);
    chmod($target, 0766); 
  }
  
  // list of $files with full path, target file with full path
  function shell_m4aconcat($files, $target) {// with mp4, we have to first transcode to WAV, then MP3, then concat    
    // convert to WAV
    foreach ($files as $file) { // setup tmp file to covert this into wav 
     $wavs[] = self::unique_filename(dirname($target) .'/m4a_tmp.wav');  
     self::shell_clean_wav($file, end($wavs)); 
    }
    // then transcode to MP3
    foreach ($wavs as $wav) {
     $mp3s[] = self::unique_filename(dirname($target) .'/m4a_tmp.mp3');  
     self::shell_transcode_speech($wav, end($mp3s));
    } 
    // Concat MP3s
    self::shell_mp3concat($mp3s, $target);
    // Clean up - delete temp files
    foreach ($wavs as $wav) unlink($wav);  
    foreach ($mp3s as $mp3) unlink($mp3);  
    chmod($target, 0766);
  } 
  

  // list of $files with full path, target file with full path
  function shell_wavconcat($files, $target) {
    // new strategy, loop through, create a tmp file for each, convert each to wave then concat
    
    foreach ($files as $file) { // setup tmp file to covert this into wav 
     $tmps[] = self::unique_filename(dirname($target) .'/concat_tmp.wav');  
     self::shell_clean_wav($file, end($tmps)); 
    }
    // now merge the wavs together
    $target_arg = escapeshellarg($target);    
    foreach ($tmps as $tmp) $args[] = escapeshellarg($tmp);
    $mrg_cmd = "cat ". implode(' ',$args) ." > {$target_arg}";  // cat audio1.wav audio2.wav audio3.wav > audio.wav
 //drupal_set_message(">>> <tt> $mrg_cmd </tt> ");
    shell_exec($mrg_cmd); 
    
    // remove all the tmp files
    foreach ($tmps as $tmp) unlink($tmp); 
  }

  // check to see if a unix command works
  function shell_cmd_exists($cmd) {
    exec($cmd, $output, $returnvalue);
    if ($returnvalue == 127) return FALSE;
    else return TRUE;
  }

  function shell_mp3wrap($files, $target) {
    // note, this messes with the file name. MP3merge is annoying that way
   // drupal_set_message("<b>shell_mp3wrap</b>");
    // merge into one file (not sure if this is any better than just CAT)
    $target_arg = escapeshellarg($target);
    foreach ($files as $file) $args[] = escapeshellarg($file);
    $mrg_cmd = "mp3wrap {$target_arg} ". implode(' ',$args); // mp3wrap target.mp3 1.mp3 2.mp3 3.mp3
    shell_exec($mrg_cmd);
  }

  function shell_read_mp3_duration($file) {
   // figure this one out yet

  }





  function noext($filename) {
    $pathinfo = pathinfo($filename);
    $result = $pathinfo['dirname']=='.' ? '' : $pathinfo['dirname'].'/';
    $result .= $pi['filename'];
    return $result;
  }


  /*
   * General file tools
   */

  // returns an array of files from the list that match the first matching $file (disregarding sequence numbers)
  // need just filenames, not full paths
  function get_sequenced_files($file, $filelist) {
   $starter = self::strip_sequence($file);
   $result = array();
   foreach ($filelist as $key=>$file) if (self::strip_sequence($file) == $starter) $result[$key] = $file;
   return $result;
  }
  // returns filename without post-pended sequence number like myfile-001.mp3 -> myfile.mp3
  function strip_sequence($filename) {
    //drupal_set_message("!! 1 strip_sequence, filename: $filename");
    $pathinfo = pathinfo($filename);
    $base = preg_replace('/-(\d+)$/', '', $pathinfo['filename']); // filename with no extension and no trailing numbers

    $result = $pathinfo['dirname']=='.' ? '' : $pathinfo['dirname'].'/';
    //drupal_set_message("!! 2 strip_sequence, result: $result, \$pathinfo['dirname']: '{$pathinfo['dirname']}'");


    $result .= $base .'.'. $pathinfo['extension'];

    return $result;
  }
  // remove spaces and lowercast filename (to make filenames more URL friendly)
  function remove_spaces_lower($string) {
    // make filename url friendly
    $string = str_replace('  ', ' ', $string);
    $string = str_replace(' ', '-', $string);
    $string = htmlentities(strtolower($string));
    $string = preg_replace("/&(.)(acute|cedil|circ|ring|tilde|uml);/", "$1", $string);
    $string = preg_replace("/([^a-z0-9]+)/", "-", html_entity_decode($string));
    $string = trim($string, "-");
    return $string;
  }
  // add sequence number to file, like: myfile.mp3 -> myfile-017.mp3
  function number_filename($filename, $number=1) {
    $filename = self::strip_sequence($filename);
    $pathinfo = pathinfo($filename);
    $number = substr('000'. $number, -3); // up to 999 files
    $result = $pathinfo['dirname']=='.' ? '' : $pathinfo['dirname'].'/';

    //drupal_set_message("number_filename, filename: $filename, path: $result, \$pathinfo['dirname']: '{$pathinfo['dirname']}'");

    $result .=  $pathinfo['filename'] ."-{$number}.". $pathinfo['extension'];
    return $result;
  }

  // like file_exists, but verifies that it is a directory - send full path
  function dir_exists($dir) {
    if (file_exists($dir)) $result = is_dir($dir);
     else $result = FALSE;
    return $result;
  }

  // switch out extension, helpful for converting files to another type
  function replace_extension($filename, $new_extension) {
    $ext = $new_extension ? '.'. trim($new_extension, '.') : '';
    return preg_replace('/\..+$/', $ext, $filename);
  }

  // grab array of all files in a directory, optionally match to file extension
  function list_files($dirname, $ext='') {
    $result = array();
    if (!self::dir_exists($dirname)) return $result;
    $ext = strtolower(trim($ext, '.'));
    $dir = opendir($dirname);
    while(false != ($file = readdir($dir)))   {
      if(($file != ".") and ($file != ".."))     {
        $fileChunks = explode(".", $file);
        if(!$ext || (strtolower($fileChunks[1]) == $ext)) $result[] = $file;
      }
    }
    closedir($dir);
    sort($result);
    return $result;
  }

  // returns the largest file in a directory - so we can see if any of our split files are too big for streaming
  function biggest_filesize($dir) {
    $dir = rtrim($dir, '/');
    $files = self::list_files($dir);
    foreach ($files as $file) if (filesize($dir.'/'.$file) > $result) $result = filesize($dir.'/'.$file);
    return $result;
  }

  // useful for shell scripts, converts any path argument into a full path (needs relative /../ interpretation perhaps)
  function arg_path($path){
   $path = rtrim($path, '/');
   if (substr($path,0,1)=='/') return $path;
    else return rtrim(rtrim(getcwd(), '/') .'/'.$path, '/');
  }

  // grab file extension
  function ext($path) {
    $pathinfo = pathinfo($path);
    return strtolower($pathinfo['extension']);
  }

  function rmkdir($path, $mode = 0777) {
   //drupal_set_message("rmkdir: {$path}");
      $path = rtrim(preg_replace(array("/\\\\/", "/\/{2,}/"), "/", $path), "/");
      $e = explode("/", ltrim($path, "/"));
      if(substr($path, 0, 1) == "/") {
          $e[0] = "/".$e[0];
      }
      $c = count($e);
      $cp = $e[0];
      for($i = 1; $i < $c; $i++) {
          if(!is_dir($cp) && !@mkdir($cp, $mode)) {
              return false;
          } else @chmod($cp, $mode);
          $cp .= "/".$e[$i];
      }
      @mkdir($path, $mode);
      @chmod($path, $mode);
      // @mkdir($path, $mode);
    //drupal_set_message("rmkdir 2: {$path}");
    return $path;
  }

  // returns a new filename that does not already exist
  function unique_filename($filename) {
   //drupal_set_message("unique_filename, filename: <tt>$filename</tt>"); 
   $target = $filename;
   while (file_exists($target)) $target =  self::number_filename($filename, $i++);
   return $target;
  }

}

