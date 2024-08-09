#!/usr/bin/perl -w

use strict;
use DBI;
use Time::localtime;
use IO::Handle;
use File::Basename;
use Mail::Sender;


open(MYSIZES, '</tmp/2009_dist');
my $total = 0;
while(<MYSIZES>) {
  chomp;
  (my $size,undef)=split(/\s+/);
  my ($amount, my $unit);
  if ($size =~ /(.*)([KM]{1})/) {
     $amount = $1;
     $unit = $2;
  }
  $total += $amount * (($unit eq 'K') ? 1000 : 1000000);
  print "$total\t$size\n";
}
close(MYSIZES);
