#!/usr/bin/perl -w

# parse and report
# see man xferlog
#0   1    2 3        4    5 6                        7    8                                                                9 10111213            14  151617
#Tue Dec  2 13:13:57 2008 0 64.19.12.74.nw.nuvox.net 4768 /home/bart/dailysrv/diftp/Data/standard/GI_11242008/chancery.txt a _ o r standardtitle ftp 0 * c

my $LOG_FILE = '/var/log/proftpd/xferlog';
my $USER = 'all';
my $DIRECTION = 'all';
my @fields = ();
my %data = ();

sub GetCommandLine 
{
   local @args = @_;
   local $_;

   while (@args && ($_ = $args[0])) {
      if (/^-(\w+)/) {
         CASE : {
           if ($1 =~ /^file/) { shift(@args); $LOG_FILE = $args[0]; last CASE; }
           if ($1 =~ /^user/) { shift(@args); $USER = $args[0]; last CASE; }
           if ($1 =~ /^dir/) { shift(@args); $DIRECTION = $args[0]; last CASE; }
         }
      } else {
         print "Oops: Unknown option : $_\n";
      }
      shift(@args);
   }
}

########################
# Start of code
########################

&GetCommandLine(@ARGV);
open(LOG_FILE, "<$LOG_FILE");
while (<LOG_FILE>) {
   chomp;
   @fields = split(/\s+/);
   $data{$fields[13]}{$fields[11]} += $fields[7];
}
close(LOG_FILE);

if ($USER eq 'all') {
   foreach $user (sort keys %data) {
      if ($DIRECTION eq 'all') {
         foreach $direction (keys %{$data{$user}}) {
            print "$user\t$direction\t$data{$user}{$direction}\n";
         }
      } else {
         print "$user\t$DIRECTION\t$data{$user}{$DIRECTION}\n" if defined($data{$user}{$DIRECTION});
      }
   }
} else {
   if ($DIRECTION eq 'all') {
      foreach $direction (keys %{$data{$USER}}) {
         print "$USER\t$direction\t$data{$USER}{$direction}\n";
      }
   } else {
      print "$USER\t$DIRECTION\t$data{$USER}{$DIRECTION}\n" if defined($data{$USER}{$DIRECTION});
   }
}
