const gearman = require('gearman')

class Rs485Service {
    let gearman;
    
    constructor(hostname, port = 4730) {
        this.gearman = gearman(hostname, port, {timeout: 3000});
        this.gearman.on('timeout', function() {
            console.log('Gearman client timeout');
            this.gearman.close();
        });
    }
    
    sendCommand(address, command) {
        console.info("Sending rs485 command. to: " + address + "; command: " + command);
        this.gearman.on('WORK_COMPLETE', function(job) {
            console.log('job completed, result:', job.payload.toString())
            this.gearman.close()
        })
    }
};

module.exports = Rs485Service;