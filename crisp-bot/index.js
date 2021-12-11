require('dotenv').config();

//import { Rs485Service } from './services/rs485.js';

const Rs485Service = require('./services/rs485.js');

let rs485 = new Rs485Service();
rs485.sendCommand('addr', 'cmd');

process.exit(0);

var Crisp = require("crisp-api");

// Create the Crisp client (it lets you access the REST API and RTM API at once)
var CrispClient = new Crisp();

// Configure your Crisp authentication tokens ('plugin' token)
console.info("Trying to authenticate with: " + process.env.CRISP_IDENTIFIER);
console.info(process.env);

CrispClient.authenticateTier("plugin", process.env.CRISP_IDENTIFIER, process.env.CRISP_KEY);

CrispClient.on("message:send", function(message) {
  // Filter on text messages
    if (message.type === "text") {
        console.info(
          "Got text message from visitor with content:", message.content
      );
      CrispClient.website.markMessagesReadInConversation(message.website_id, message.session_id, true);
    }
    console.info(message);

console.info("Sending message...");

CrispClient.website.sendMessageInConversation(
  message.website_id, message.session_id,

  {
    type    : "text",
    content : "This is a message sent from node-crisp-api examples. " + message.content,
    from    : "operator",
    origin  : "chat"
  }
)
  .then((data) => {
    console.info("Sent message.", data);
  })
  .catch((error) => {
    console.error("Failed sending message:", error);
  });
  
});

