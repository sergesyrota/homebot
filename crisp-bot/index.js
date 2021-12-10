var Crisp = require("crisp-api");

// Create the Crisp client (it lets you access the REST API and RTM API at once)
var CrispClient = new Crisp();

// Configure your Crisp authentication tokens ('plugin' token)
CrispClient.authenticateTier("plugin", "", "");

CrispClient.on("message:send", function(message) {
  // Filter on text messages
    if (message.type === "text") {
        console.info(
          "Got text message from visitor with content:", message.content
      );
    }
});

