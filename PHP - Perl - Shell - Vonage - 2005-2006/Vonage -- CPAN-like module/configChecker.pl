#!/usr/bin/perl -w

=head1 NAME

	configChecker.pl
	
=head1 SYNOPSIS

	/usr/local/bin/configChecker.pl -rtp | -bsp | -both 
                                        [-ip ListOfIPsToCheck] 
                                        -user YourLinusLogin -pass YourLinusPasswd

	where
		-rtp     : only RTP Relay systems will be checked
		-bsp     : only proxies will be checked
		-both    : check both type of systems (default)
		-ip      : File containing list of IPs to login to and check config file
			Defaults are :
				./BSPs/bsps
				./RTPs/rtps            
                        File format : #Private IP  Public IP        BSP version
                                      12.34.56.78  192.192.192.192  1
                                      12.34.56.79  192.192.192.191  2


=head1 AUTHOR

        Production Call Proc 07/2005

=head1 DESCRIPTION

	The script hops through Linus/MC_Linus onto a specified RTP/BSP and
	checks the config file with a local (in database ?) template.
	Differences are reported to TheGoodGuys of Prod Call Proc

=head1 REQUIREMENTS

        Perl 5.0.8 minimal (confirm with 'perl -v')
        Expect.pm package from CPAN (confirm with 'perldoc Expect')
	Vonage.pm CPAN package developed by ProdCallProc
	Access to Linus (12.34.56.79)
        Mailer with subject spec (/usr/bin/Mail or /usr/ucb/Mail)

=head1 INSTALLATION

	on xxxx.vonage.net (xx.xx.xx.xx)
        	Copy file to usr/local/bin
        	Make the script executable for users

=cut

use Expect;
use Vonage;

my $TYPE = 'BOTH';
my ($RTP_LIST, $RTP_TEMPLATE, $RTP_CONFIG) = ('/home/configs/RTPs/rtps', 
                                              '/home/configs/RTPs/rtptemplate', 
                                              '/opt/rtprelay/config',
                                             );
my ($BSP_LIST, $BSP_TEMPLATE, $BSP_CONFIG, $BSP_V2_TEMPLATE) = ('/home/configs/BSPs/bsps', 
                                                                '/home/configs/BSPs/bsptemplate', 
                                                                '/opt/bsp/config',
                                                                '/home/configs/BSPs/bsp_v2_template',
                                                               );

my ($ME, $ME_PASSWD, $PROBLEM) = ('', '', 'Could not get into this host');
my (%RTPs, %RTP_Templates) = ((),());
my (%BSPs, %BSP_Templates, %BSP_Installed) = ((),(),());
my ($BSP_VERSION, $PROXY_ID) = (1, 0);

# Overwrites the value defined in Vonage.pm
#my $MAIL_LIST='marcel.quirijnen@vonage.com';

#####
# Actual RTP check routine
# For each system, hop through linus/mc_linus and retrieve config information
#####
sub CheckRTPs
{
   my ($session, $rtp);

   foreach $rtp (sort keys %RTPs) {
      if ( ! GetSystemTemplate('RTP', $rtp)) {

         push (@{$RTP_Templates{MARSHAL_LISTEN_IP}}, ($rtp, 0, 0));
         push (@{$RTP_Templates{EXTERNAL_RTP_LISTEN_IP}}, ($rtp, 0, 0));
         push (@{$RTP_Templates{INTERNAL_RTP_LISTEN_IP}}, ($rtp, 0, 0));

         $session = HopToHost($rtp, $ME, $ME_PASSWD);

         #$session->debug(3);
         #$session->exp_internal(3);
         $session->log_stdout(0);
         $session->raw_pty(1);
   
         $session->send("/bin/cat $RTP_CONFIG\n");
         $session->expect(8, [ timeout            => \&HandleConfigPattern, ($rtp, 'timeout') ],
                             [ qr/\/bin\/cat.*\n/ => sub { exp_continue; }],
                             [ qr/\n*bash-.*\$ /  => \&HandleConfigPattern, ($rtp, 'before') ],
                             [ qr/ noc]\$ /       => \&HandleConfigPattern, ($rtp, 'before') ],
                             [ qr/bash-.*\$ /     => sub { exp_continue; }],
                             [ qr/Password:/      => sub { exp_continue; }]);
                             #[ qr/\w+=.*\n/m     => \&HandleConfigPattern, ($rtp, 'match') ]);
         HopBackHome($session);
         &GetRetrievedRTPInfo;
         ReportDifferencesBetweenRTPConfigs($rtp);
      }
      %RTP_Templates = ();
   }
}

sub HandleIDPattern
{
   my ($self) = @_;
   $PROXY_ID = $self->before();
}

sub HandleV2Pattern
{
   my ($self, $ip, $mode) = @_;
   my ($row, $key, $val);
   
   if ($mode eq 'before') {
      my @data = split(/\n/,$self->before());
      shift @data; shift @data; shift @data; shift @data;      
      pop @data;
      foreach $row (@data) {
         (undef, undef, $key, $val, undef) = split(/\|/, $row, 5);
         $key =~ s/\s+//g; 
         $val =~ s/\s+//g; 
         push @{$BSP_Templates{$key}}, $val;
      }
#      foreach (keys %BSP_Templates) {
#         print $_, "\t";
#         foreach $tt (@{$BSP_Templates{$_}}) {
#            print $tt, "\t";
#         }
#         print "\n";
#      }
   }
}

sub HandleVersion2BSP
{
   ($session, $bsp) = @_;

   &_testProxy($bsp);
   $session->send("/bin/egrep PROXY_ID $BSP_CONFIG | /bin/cut -d'=' -f2\n");
   $session->expect(5, [ qr/\/bin\/egrep.*f2\n/ => sub { exp_continue; }],
                       [ qr/\[noc\@.* noc\]\$ / => \&HandleIDPattern ],
                       [ qr/\n-bash-.*\$ /      => \&HandleIDPattern ]);

   # Get static values from the box, so we know that replication works ok
   $session->send("/usr/bin/mysql CentralDB -u$CALLPROC -p -e \"select * from ProxyConfig where ID=0\"\n");
   $session->expect(5, [ qr/\/usr\/bin\/mysql.*\n/ => sub { exp_continue; }],
                       [ qr/er password: /         => sub { my $exp = shift; 
                                                            $exp->send("$CALLPROC_PASSWD\n");
                                                            exp_continue; } ],
                       [ qr/\[noc\@.* noc\]\$ /    => \&HandleV2Pattern, ($bsp, 'before') ],
                       [ qr/\n-bash-.*\$ /         => \&HandleV2Pattern, ($bsp, 'before') ]);

   my $str = 'select * from ProxyConfig where ID=' . $PROXY_ID;
   $session->send("/usr/bin/mysql CentralDB -u$CALLPROC -p -e \"$str\"\n"); 
   $session->expect(5, [ qr/\/usr\/bin\/mysql.*\n/ => sub { exp_continue; }],
                       [ qr/er password: /         => sub { my $exp = shift; 
                                                            $exp->send("$CALLPROC_PASSWD\n");
                                                            exp_continue; } ],
                       [ qr/\[noc\@.* noc\]\$ /    => \&HandleV2Pattern, ($bsp, 'before') ],
                       [ qr/\n-bash-.*\$ /         => \&HandleV2Pattern, ($bsp, 'before') ]);

   return $session;
}


#####
# Actual BSP check routine
# For each system, hop through Linus and retrieve config information
#####
sub CheckBSPs
{
   my ($session, $bsp);
   my $rc = 0;

   foreach $bsp (sort keys %BSPs) {
      if ( ! GetSystemTemplate('BSP', $bsp)) {
         $session = HopToHost($bsp, $ME, $ME_PASSWD);
         #$session->debug(3);
         #$session->exp_internal(3);
         $session->log_stdout(0);
         $session->raw_pty(1);
         if ($BSP_VERSION == 1) {
            $session->send("/bin/cat $BSP_CONFIG\n");
            $session->expect(5, [ timeout                => \&HandleConfigPattern, ($bsp, 'timeout') ],
                                [ qr/\/bin\/cat.*\n/     => sub { exp_continue; }],
                                [ qr/Password:/          => sub { exp_continue; }],
                                [ qr/\[noc\@.* noc\]\$ / => \&HandleConfigPattern, ($bsp, 'before') ],
                                [ qr/\n-bash-.*\$ /      => \&HandleConfigPattern, ($bsp, 'before') ]);
            HopBackHome($session);
            $rc = GetRetrievedBSPInfo($bsp);
         } else {
            $session = HandleVersion2BSP($session, $bsp);
            HopBackHome($session);
         }
         ReportDifferencesBetweenBSPConfigs($bsp, $rc, $PROBLEM);
      }
      %BSP_Templates = ();
      $rc = 0;
      $PROXY_ID = 0;
   }
}

sub _testProxy
{
   my ($proxy) = @_;
   my $found = 0;

   foreach (@TEST_NETS) {
      $found = 1 if $proxy =~ /$_/;
   }
   if ($found) {
      delete $BSP_Templates{PROXY_UPDATE_ADDRESS};
      delete $BSP_Templates{PROXY_GROUP_ADDRESS};
   }
   return $found;
}

sub _patch_ED_RTP
{
   my ($patchThis) = @_;

   DONE : foreach (keys %ED_RTPs) {
      if ($patchThis =~ /$_/) {
         $patchThis = $ED_RTPs{$_};
         last DONE; 
      }
   }
   return $patchThis;
}


########################
# create a list of systems to be checked
########################
sub GetSystemList
{
   my ($type) = @_;
   my ($syslist, $rtp);

   $syslist = ($type eq 'RTP') ? $RTP_LIST : $BSP_LIST;
   open (SYSTEMS, "< $syslist") || die "Can't open system list file : $syslist\n";
   while (<SYSTEMS>) {
      next if /^#/;
      next if /^$/;
      chomp;

      if ($type =~ /RTP/i) {
         $RTPs{_patch_ED_RTP($_)} = 1;
      } else {
         (my $private, my $public, my $version) = split(/\s+/, $_, 3);
         push @{$BSPs{$private}}, ($public, $version);
      }
   }
   close(SYSTEMS);
}


########################
# Get the template for the specified system type
########################
sub GetSystemTemplate
{
   my ($type, $system) = @_;
   my ($syslist, $key, $val);

   if ($type eq 'RTP') {
      $syslist = $RTP_TEMPLATE;
   } elsif ($type eq 'BSP') {
      $BSP_VERSION = ${$BSPs{$system}}[1];
      $syslist = $BSP_VERSION == 1 ? $BSP_TEMPLATE : $BSP_V2_TEMPLATE;
   }
   open(TEMPLATES, "<$syslist") || die "Can't open system template file : $syslist\n";
   while (<TEMPLATES>) {
      next if /^#/;
      next if /^$/;
      chomp;
      ($key, $val) = split(/=/, $_, 2);
      chomp($key); chomp($val);
      if ($type =~ /RTP/i) {
         # store multi values in 1 hash key
         # format : actual value in template, actual value installed on system, ok flag
         push ( @{$RTP_Templates{$key}}, ($val, 0, 0, 0)) if $type eq 'RTP';
      } else {
         $val =~ s/99\.99\.99\.99/${$BSPs{$system}}[0]/;        # public
         $val =~ s/98\.98\.98\.98/$system/;                     # private
         $val =~ s/00\.00\.00\.00//;
         push @{$BSP_Templates{$key}}, $val if $key !~ /_ADDRESS/ && $key !~ /POSITION_SERVER_911_URL/;
      }
   }
   close(TEMPLATES);

   if ($type =~ /BSP/i) {
      # get supposedly correct IPs from DB
      # this script is written by BJ
      my @IPs = qx { /home/configs/getIPs.pl $system };
      return 1 if $? >> 8;

      # cleanup struct
      shift(@{$BSP_Templates{PROXY_PORT}});
      shift(@{$BSP_Templates{PROXY_LISTEN_PORT}});
   
      foreach (@IPs) {
         chomp;
         ($key, $ip, $port) = split(/\s+/, $_, 3); 
         if ($key =~ /PROXY_GROUP_ADDRESS/) {
            ${$BSP_Templates{PROXY_PORT}}[0] = $port;
         } elsif ($key =~ /PROXY_UPDATE_ADDRESS/) {
            ${$BSP_Templates{PROXY_LISTEN_PORT}}[0] = $port;
         }
         push (@{$BSP_Templates{$key}}, "$ip:$port");
      }
      delete $BSP_Templates{PROXY_ID} if $BSP_VERSION == 1;

#      foreach (@MC_NETS) {
#         delete $BSP_Templates{PROXY_UPDATE_ADDRESS} if $system =~ /$_/;
#      }

#      foreach (keys %BSP_Templates) {
#         print $_, "\t";
#         foreach $tt (@{$BSP_Templates{$_}}) {
#            print $tt, "\t";
#         }
#         print "\n";
#      }
   }
   return 0;
}


sub HandleConfigPattern
{
   my ($self, $ip, $mode) = @_;

   open (CONFIG, "+>/tmp/config.$$") || die "Can't retrieve config file.\n";
   if ($mode eq 'timeout') {
      print CONFIG "$ip=$PROBLEM\n";
   } elsif ($mode eq 'before') {
      print CONFIG $self->before(), "\n";
   } elsif ($mode eq 'match') {
      print CONFIG $self->match(), "\n";
   } else {
      print CONFIG $self->match(), "\n";
      print CONFIG $self->after();
   }
   close(CONFIG);
}


sub ReportDifferencesBetweenRTPConfigs
{
   my ($rtp) = @_;
   my ($key, $heading, $trailer);

   open(DIFFS, "+>>/tmp/rtpdiffs.$$") || die "Could not create difference file\n";
   $heading = $trailer = 0;
   foreach $key (keys %RTP_Templates) {
      if ($key eq $rtp) {
         print DIFFS "$rtp\t${$RTP_Templates{$key}}[0]\n";
         $heading++;
      } else {
         if (${$RTP_Templates{$key}}[2] == 1 or ${$RTP_Templates{$key}}[2] == 2) {
            if (!$heading) {
               print DIFFS "Differences for RTP : $rtp\n\tkey\t\tIn Template\t\tInstalled\n";
               $heading++;
               $trailer++;
            }
            print DIFFS "\t$key\t\t${$RTP_Templates{$key}}[0]\t\t${$RTP_Templates{$key}}[1]\n";
         }
      }
   }
   print DIFFS "\n\n" if $trailer;
   print DIFFS "$rtp : OK\n" if ! $heading;
   close(DIFFS);
}


sub ReportDifferencesBetweenBSPConfigs
{
   my ($bsp, $nok, $err) = @_;
   my ($key, $heading, $trailer);
   my %records = ();

   open(DIFFS, "+>>/tmp/bspdiffs.$$") || die "Could not create difference file\n";
   if ($nok) {
      print DIFFS "$bsp : $err\n";
   } else {
      $heading = $trailer = 0;
      foreach $key (keys %BSP_Templates) {
         if ($key =~ /PUBLISHER_ADDRESS/ || $key =~ /PROXY_GROUP_ADDRESS/  ||
             $key =~ /MERCURY_ADDRESS/   || $key =~ /PROXY_UPDATE_ADDRESS/ ||
             $key =~ /POSITION_SERVER_911_URL/) {

            %records = ();
            my $noofrecs = scalar(@{$BSP_Templates{$key}});
            my @correct = splice(@{$BSP_Templates{$key}}, 0, $noofrecs/2);
            foreach (@correct) {
               $records{$_} = 1;
            }
            foreach (@{$BSP_Templates{$key}}) {
               $records{$_} += 2;
            }
            foreach $val (keys %records) {
               if ($records{$val} != 3) {
                  if (!$heading) {
                     print DIFFS "Differences for BSP : $bsp (version : ${$BSPs{$bsp}}[1])\n";
                     $heading++;
                     $trailer++;
                  }
                  print DIFFS ($records{$val} == 1) ? 'In template : ' : 'Installed : ';
                  print DIFFS $key, "\t", $val, "\n";
               }
            }

         } else { 
            if ($key ne 'GROUP_ID' && $key ne 'PROXY_ID' && $BSP_VERSION == 1) {
               if (${$BSP_Templates{$key}}[0] ne ${$BSP_Templates{$key}}[1]) {
                  if (!$heading) {
                     print DIFFS "Differences for BSP : $bsp\n\tkey\tIn Template\tInstalled\n";
                     $heading++;
                     $trailer++;
                  }
                  print DIFFS "\t$key\t<${$BSP_Templates{$key}}[0]>\t<${$BSP_Templates{$key}}[1]>\n";
               }
            }
         }
      }
      print DIFFS "\n\n" if $trailer;
      print DIFFS "$bsp (version : ${$BSPs{$bsp}}[1]) : OK\n" if ! $heading;
   }
   close(DIFFS);
}


sub GetRetrievedBSPInfo
{
   my ($bsp) = @_;
   my ($key, $val);

   open(BSPINSTALLED, "< /tmp/config.$$") || die "Can't open retrieved BSP data file\n";
   while (<BSPINSTALLED>) {
      next if /^$/;
      next if /^\s+$/;
      next if /^#/;
      s///g;             # man, someone pasted some real crap in these files - is Bill Gates involved ?
      chomp;
      ($key, $val) = split(/=/, $_, 2);
      push (@{$BSP_Templates{$key}}, $val) if $val ne $PROBLEM;
   }
   close(BSPINSTALLED);
#   foreach $key (keys %BSP_Templates) {
#      print "$key\t\t";
#      foreach (@{$BSP_Templates{$key}}) {
#         print "$_\t";
#      }
#      print "\n";
#   }
   return $val eq $PROBLEM ? 1 : 0; 
}


sub GetRetrievedRTPInfo
{
   my ($key, $val);

   open(RTPINSTALLED, "< /tmp/config.$$") || die "Can't open retrieved RTP data file\n";
   while (<RTPINSTALLED>) {
      next if /^$/;
      next if /^#/;
      next if /-bash.*/;
      next if /\[\w+@.*\]$/;
      next if ! /\w+=\w+/;
      chomp;
      ($key, $val) = split(/=/, $_, 2);
      $val =~ s/\r//;
      if (exists($RTP_Templates{$key})) {
         # key was in template
         if (${$RTP_Templates{$key}}[0] ne $val) {
            # value in template and system are different
            if (${$RTP_Templates{$key}}[0] =~ /99\.99\.99\.99/) {
               ${$RTP_Templates{$key}}[0] = $val;
            } else {
               ${$RTP_Templates{$key}}[1] = $val;
               ${$RTP_Templates{$key}}[2] = 2;      #flag as nok because of different values
            }
         }
      } else {
         # key was not in template
         push(@{$RTP_Templates{$key}}, ($val, 0, 1));    # flag as nok because of not avail
      }
   }
   close(RTPINSTALLED);
}

################
# Get commandline options
################
sub GetCommandLine 
{
   local @args = @_;
   local $_;
   my $ip_file = '';

   while (@args && ($_ = $args[0])) {
      if (/^-(\w+)/) {
         CASE : {
           if ($1 =~ /^rtp/)     { $TYPE = 'RTP'; last CASE; }
           if ($1 =~ /^bsp/)     { $TYPE = 'BSP'; last CASE; }
           if ($1 =~ /^both/)    { $TYPE = 'BOTH'; last CASE; }
           if ($1 =~ /^user/)    { shift(@args); $ME = $args[0]; last CASE; }
           if ($1 =~ /^pass/)    { shift(@args); $ME_PASSWD = $args[0]; last CASE; }
           if ($1 =~ /^ip/)      { shift(@args); $ip_file = $args[0]; last CASE; }
           #if ($1 =~ /^version/) { shift(@args); $BSP_VERSION = $args[0]; last CASE; }
         }
      } else {
         print "Oops: Unknown option : $_\n";
      }
      shift(@args);
   }
   if (length($ip_file)) {
      if ($TYPE eq 'RTP') {
         $RTP_LIST = $ip_file;
      } else {
         $BSP_LIST = $ip_file;
      } 
   }
}

########################
# Start of code
########################

&GetCommandLine(@ARGV);
die "Please enter your xyz userId and password (-user LinusAccount -pass LinusPasswd).\n" if ! $ME || ! $ME_PASSWD;

if ($TYPE =~ /RTP/i || $TYPE =~ /BOTH/i) {
   &GetSystemList('RTP');
   &CheckRTPs;
   qx { $MAILER -s "Differences in RTP config files" $MAIL_LIST </tmp/rtpdiffs.$$ };
   unlink "/tmp/rtpdiffs.$$", "/tmp/config.$$";
}
if ($TYPE =~ /BSP/i || $TYPE =~ /BOTH/i) {
   &GetSystemList('BSP');
   &CheckBSPs;
   qx { $MAILER -s "Differences in BSP config files" $MAIL_LIST </tmp/bspdiffs.$$ };
   unlink "/tmp/bspdiffs.$$", "/tmp/config.$$";
}
