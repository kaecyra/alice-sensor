{
    "server": {
        "mode": "ws",
        "host": "localhost",
        "port": 8080,
        "retry": {
            "delay": 15
        }
    },
    "sensor": {
        "type": "motion",
        "id": "lrmirror01-motion",
        "name": "Livingroom Mirror Motion",
        "server": {
            "path": "/sensor"
        },
         "settings": {
            "gpio": {
                "pin": 4
            }
        }
    }
}