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
    "sensor": {
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
    }
}