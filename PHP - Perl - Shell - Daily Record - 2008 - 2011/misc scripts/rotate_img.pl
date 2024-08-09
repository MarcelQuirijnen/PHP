#!/usr/bin/perl -w

# Image rotator, including thumbnails
#
# ./rotate_img.pl -dir imgs -sub images -rot 90
# ./rotate_img.pl -dir 2009_08_06_10_27_470 -sub 02022201 -rot 90

use strict;
use warnings;
use Time::localtime;
use IO::Handle;
use File::Basename;
use Mail::Sender;

my ($DIR, $SUB, $ROT, $DOCINDEXER_DIST, $DOCINDEXER_INCOMING) = ('','','','/array/home/docindexer/di/dist','/array/home/docindexer/di/incoming');


sub GetCommandLine 
{
   my @args = @_;
   local $_;

   while (@args && ($_ = $args[0])) {
      if (/^-(\w+)/) {
         CASE : {
           # 2010_01_25_11_09_280
           if ($1 =~ /^dir/)    { shift(@args); $DIR = $args[0]; last CASE; }
           # 01222010-3
           if ($1 =~ /^sub/)    { shift(@args); $SUB = $args[0]; last CASE; }
           # 90 / 180 / 270 
           if ($1 =~ /^rot/)    { shift(@args); $ROT = $args[0]; last CASE; }
         }
      } else {
         print "Oops: Unknown option : $_\n";
      }
      shift(@args);
   }
}

sub SendMail
{
   my ($subject, $to, $cc, $mesg, $attach) = @_;
   
   my $sender = new Mail::Sender {
      smtp => 'xx.yy.com', 
      port => 2525,
      from => 'abc@xx.yy.com'
   };
 
   $sender->MailFile({
      to      => $to, 
      cc      => $cc, 
      subject => $subject, 
      msg     => $mesg, 
      file    => ($attach ? $attach : ''),
   });
}

sub rotateImage
{
    my ($tif) = @_;   # $tif is filename only, no path info

#shell_exec("tifftopnm -quiet ".$tmpfile." > ".$tmpfile.".remove.1");
#shell_exec("pnmflip -r".$angle." ".$tmpfile.".remove.1 > ".$tmpfile.".remove.2");
#copy($tmpfile.".remove.2",$tmpfile.".remove.1");
#shell_exec("pnmtotiff -g4 ".$tmpfile.".remove.1 > ".$tmpfile.".remove.3");
#copy($tmpfile.".remove.3",$tmpfile);
#shell_exec("pnmscalefixed -quiet -xsize=80 ".$tmpfile.".remove.1|pnmtopng -quiet > ".$tmpfile.".png");   #thumbnail = /tmb/$tif.png
#shell_exec("pnmscale -quiet -xsize=700 ".$tmpfile.".remove.1|pnmtopng -quiet > ".$tmpfile."main.png");   #main image = /tmb/$tifmain.png
   
    my($filename, $directory, $suffix) = fileparse("$DOCINDEXER_INCOMING/$DIR/$SUB/$tif", qr/\.[^.]*/);
    
    qx { /usr/bin/tifftopnm -quiet "$DOCINDEXER_INCOMING/$DIR/$SUB/$tif" > "/tmp/$filename" };
    qx { /usr/bin/pnmflip -r$ROT "/tmp/$filename" > "/tmp/$filename.rot" };
    qx { /usr/bin/pnmtotiff -g4 "/tmp/$filename.rot" > "$DOCINDEXER_INCOMING/$DIR/$SUB/$tif" }; 
    qx { /usr/bin/pnmtotiff -g4 "/tmp/$filename.rot" > "$DOCINDEXER_DIST/$DIR/$SUB/$tif" };
    qx { /usr/bin/pnmscalefixed -quiet -xsize=80 "/tmp/$filename.rot" | /usr/bin/pnmtopng -quiet > "$DOCINDEXER_DIST/$DIR/$SUB/tmb/$filename"'tif.png' };
    qx { /usr/bin/pnmscale -quiet -xsize=700 "/tmp/$filename.rot" | /usr/bin/pnmtopng -quiet > "$DOCINDEXER_DIST/$DIR/$SUB/tmb/$filename"'tifmain.png' };

# copy /tmp/rotNlDmBo /array/home/docindexer/di/incoming/2010_01_25_11_09_280/01222010-3/Page0001.tif
# copy /tmp/rotNlDmBo.png /array/home/docindexer/di/dist/2010_01_25_11_09_280/01222010-3/tmb/Page0001tif.png
# copy /tmp/rotNlDmBomain.png /array/home/docindexer/di/dist/2010_01_25_11_09_280/01222010-3/tmb/Page0001tifmain.png 

    qx { /bin/rm -f /tmp/$filename /tmp/$filename.rot };

}


###
# Start of code 
####

GetCommandLine(@ARGV);
die "Please, specify folder, subfolder and rotation\n" if ! $DIR || ! $SUB || ! $ROT;
die "Please specify correct rotation angles, ie. 90, 180 or 270" if $ROT != 90 && $ROT != 180 && $ROT != 270;

opendir(IMD, "$DOCINDEXER_INCOMING/$DIR/$SUB") || die("Cannot open $DOCINDEXER_INCOMING/$DIR/$SUB folder");
my @thefiles= readdir(IMD);
closedir(IMD);
        
foreach my $f (@thefiles)
{
    next if -d "$DOCINDEXER_INCOMING/$DIR/$SUB/$f"; 
    next if $f eq ".";
    next if $f eq "..";
    print ("rotating $f\n");
    rotateImage($f);

}

SendMail('Images rotated', 'marcel@xyz.com', undef, 'done ok', undef);
