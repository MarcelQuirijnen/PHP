#!/usr/bin/perl

use strict;
use warnings;
use Data::Dumper;
use JSON;
use LWP;
use LWP::ConnCache;
use LWP::Simple qw(!head);
use ZMQ::LibZMQ3;
use ZMQ::Constants;
#use Net::Server::Daemonize qw(daemonize);
use threads;
use Thread::Pool;
use Encode;
use HTTP::Cookies;
use load qw(AutoLoader now);

$| = 1;

my $req = <>;
$req = from_json($req);

my $queue;
my @qPos;
my $maxThread = 1;

my $ua = LWP::UserAgent->new;
$ua->agent('Optanix Platform');
my $cookie_jar = HTTP::Cookies->new();
$ua->cookie_jar($cookie_jar);
$ua->timeout(30);
$ua->conn_cache(LWP::ConnCache->new('total_capacity'=>20));

my $pool = Thread::Pool->new({'do'=>\&myPollHost, workers=>$maxThread, optimize=>'cpu'});

for (my $i = 0; $i < @{$req}; $i++){
	unless ($queue->{$req->[$i]->{'host'}}){
		push(@qPos, scalar($req->[$i]->{'host'}));
		$queue->{scalar($req->[$i]->{'host'})} = [];
		push(@{$queue->{scalar($req->[$i]->{'host'})}}, $req->[$i]);
	}
	else {
		push(@{$queue->{scalar($req->[$i]->{'host'})}}, $req->[$i]);
	}
}

foreach my $host (@qPos){
	$pool->job($host, $queue->{$host});
}

$pool->shutdown;

sub myPollHost {
	my $host = shift;
	my $aQueue = shift;
	while (my $action = shift(@{$aQueue})){
        	my $aTarget;
        	my $uri = ($action->{'ssl'} ? 'HTTPS' : 'HTTP') . '://' . $host . ($action->{'port'} ? (':' . $action->{'port'}) : '') . $action->{'path'};
        	if (($action->{'username'}) && ($action->{'password'})){
        	        $aTarget = $host . ':' . ($action->{'port'} ? $action->{'port'} : ($action->{'ssl'} ? 443 : 80));
        	        $ua->credentials($aTarget, '', $action->{'username'} => $action->{'password'});
        	}
		if ($action->{'cookies'}){
			foreach my $cookie (keys %{$action->{'cookies'}}){
				$cookie_jar->set_cookie(0, $cookie, $action->{'cookies'}->{$cookie}, '/', $host, ($action->{'port'} ? $action->{'port'} : ($action->{'ssl'} ? 443 : 80)),0,0,86400,0);
			}
		} 
        	my $r = HTTP::Request->new(($action->{'action'} ? $action->{'action'} : 'GET'), $uri, undef, ($action->{'data'} ? encode('utf8', $action->{'data'}) : undef));
		if ($r){
			$cookie_jar->add_cookie_header($r);
        		my $resp = $ua->request($r);
        		if (($resp->status_line eq '401 Unauthorized') && ($action->{'username'}) && ($action->{'password'})){
        		        $resp->header('WWW-Authenticate') =~ m/^Basic realm="(.*)"$/;
        		        $ua->credentials($aTarget, $1, $action->{'username'} => $action->{'password'});
        		        $resp = $ua->request($r);
        		}
        		if ($resp->is_success) {
#        			print "HOST: $host\nREQUEST: $uri\nRESPONSE: " . $resp->decoded_content . "\n\n";
        			print $resp->decoded_content . "\n";
#MEH
#        			print . $resp->decoded_content . "\n\n";
			}
		}
	}
}

