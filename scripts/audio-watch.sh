#!/bin/bash

me=`basename $0`
dir=`dirname $0`
if [ $# -lt 2 ]; then
    echo "Usage: $me <keyphrase> <pid>"
    exit 1;
fi

keyphrase="$1"
pid="$2"

# Listen
pocketsphinx_continuous -agc max -vad_threshold 3.0 -inmic yes -keyphrase $1 2>/dev/null | "$dir/audio-keyword.php"

# Notify that we've stopped
kill -10 $pid