#!/usr/bin/env perl
# HTTPS fallback for restricted hosts with Perl core and OpenSSL.
# Every TLS connection, including redirects, must verify its certificate and hostname.
use strict;
use warnings;
use IPC::Open2 qw(open2);

my ($url, $output) = @ARGV;
die "usage: HTTPS_URL OUTPUT\n" unless defined $url && defined $output;
my $temporary = "$output.part.$$";
$SIG{ALRM} = sub { die "HTTPS download timed out\n" };
alarm 600;

sub exact {
    my ($in, $out, $count) = @_;
    while ($count > 0) {
        my $n = read($in, my $buf, $count > 65536 ? 65536 : $count);
        die "truncated response\n" unless defined($n) && $n > 0;
        print {$out} $buf or die "write failed\n";
        $count -= $n;
    }
}

sub body {
    my ($in, $out, $headers) = @_;
    if (($headers->{'transfer-encoding'} // '') =~ /\bchunked\b/i) {
        while (1) {
            my $line = <$in>;
            die "invalid chunk size\n" unless defined($line) && length($line) <= 256;
            $line =~ /^([0-9a-fA-F]+)(?:;[^\r\n]*)?\r?\n$/ or die "invalid chunk size\n";
            my $size = hex($1);
            die "chunk too large\n" if $size > 2147483648;
            if ($size == 0) {
                while (defined(my $trailer = <$in>)) {
                    die "long HTTP trailer\n" if length($trailer) > 8192;
                    last if $trailer =~ /^\r?\n$/;
                }
                last;
            }
            exact($in, $out, $size);
            my $end = '';
            my $n = read($in, $end, 2);
            die "invalid chunk delimiter\n" unless defined($n) && $n == 2 && $end eq "\r\n";
        }
    } elsif (defined $headers->{'content-length'}) {
        $headers->{'content-length'} =~ /^\d+$/ or die "invalid content-length\n";
        die "response too large\n" if $headers->{'content-length'} > 2147483648;
        exact($in, $out, int($headers->{'content-length'}));
    } else {
        while (1) {
            my $n = read($in, my $buf, 65536);
            die "read failed\n" unless defined $n;
            last if $n == 0;
            print {$out} $buf or die "write failed\n";
        }
    }
}

my $completed = 0;
my $ok = eval {
    for my $hop (0 .. 5) {
        $url =~ m{\Ahttps://([a-zA-Z0-9.-]+)(?::443)?(/[^\r\n#]*)?\z}
            or die "only HTTPS URLs on port 443 are supported\n";
        my ($host, $path) = ($1, $2 // '/');
        $path = '/' if $path eq '';
        my ($reader, $writer);
        my $pid = open2($reader, $writer, 'openssl', 's_client', '-connect', "$host:443",
            '-servername', $host, '-verify_hostname', $host, '-verify_return_error', '-quiet', '-ign_eof');
        binmode $reader;
        binmode $writer;
        print {$writer} "GET $path HTTP/1.0\r\nHost: $host\r\nConnection: close\r\nAccept-Encoding: identity\r\n\r\n"
            or die "cannot send request\n";
        close $writer;
        my $status_line = <$reader>;
        die "HTTP status missing\n" unless defined($status_line) && length($status_line) <= 8192;
        $status_line =~ m{^HTTP/1\.[01] (\d{3})\b} or die "invalid HTTP response\n";
        my $status = int($1);
        my %headers;
        my $header_bytes = length($status_line);
        while (1) {
            my $line = <$reader>;
            die "truncated headers\n" unless defined $line;
            $header_bytes += length($line);
            die "oversized headers\n" if $header_bytes > 65536;
            last if $line =~ /^\r?\n$/;
            $line =~ /^([a-zA-Z0-9-]+):\s*([^\r\n]*)/ or die "invalid header\n";
            $headers{lc($1)} = $2;
        }
        if ($status >= 300 && $status <= 308 && $status != 304) {
            my $location = $headers{'location'} // die "redirect without location\n";
            $location = "https://$host$location" if $location =~ m{\A/[^/]};
            $location =~ m{\Ahttps://}i or die "refusing non-HTTPS redirect\n";
            die "too many redirects\n" if $hop == 5;
            open my $discard, '>', '/dev/null' or die "cannot discard redirect\n";
            body($reader, $discard, \%headers);
            close $discard;
            close $reader;
            waitpid($pid, 0);
            die "TLS validation failed\n" unless $? == 0;
            $url = $location;
            next;
        }
        die "HTTP error: $status\n" unless $status >= 200 && $status < 300;
        open my $out, '>', $temporary or die "cannot create output\n";
        binmode $out;
        body($reader, $out, \%headers);
        close $out or die "cannot finish output\n";
        close $reader;
        waitpid($pid, 0);
        die "TLS verification or transfer failed\n" unless $? == 0;
        rename $temporary, $output or die "cannot save output\n";
        $completed = 1;
        last;
    }
    die "too many redirects\n" unless $completed;
    1;
};
my $error = $@;
unlink $temporary if -e $temporary;
die $error unless $ok;
