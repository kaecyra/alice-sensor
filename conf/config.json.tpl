{
    "server": {
        "mode": "ws",
        "host": "localhost",
        "address": "127.0.0.1",
        "port": 8080,
        "path": "/sensor",
        "retry": {
            "delay": 15
        }
    },
    "zero": {
        "host": "127.0.0.1",
        "port": 19501,
        "syncport": 19502
    },
    "sensors": [
        {
            "type": "motion",
            "id": "lrmirror01-motion",
            "name": "Livingroom Mirror Motion",
            "settings": {
                "gpio": {
                    "pin": 4
                },
                "extensions": [
                    {
                        "type": "screen"
                    }
                ]
            }
        },
        {
            "type": "audio",
            "id": "audio-input01",
            "name": "Livingroom Audio Input",
            "settings": {
                "record": {
                    "rate": "16k",
                    "silence": {
                        "top": 0.1,
                        "bottom": 2.0,
                        "pad": 5
                    }
                },
                "extensions": [
                    {
                        "type": "microphone"
                    }
                ]
            }
        }
    ]
}