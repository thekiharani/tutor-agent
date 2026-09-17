#!/usr/bin/env python3.6
import nltk
from nltk.stem.lancaster import LancasterStemmer
stemmer = LancasterStemmer()

import numpy
import tflearn
import tensorflow
import random
import json
import pickle

#receive Json from php
php_json = json.loads(sys.argv[1])
php_request = php_json[0]

with open("intents.json") as file:
    data = json.load(file)
try:
    with open("data.pickle","rb") as f:
        words,labels, training, output = pickle.load(f)

except:
    words = []
    labels = []
    docs_x = []
    docs_y = []

    for intent in  data["intents"]:
        for pattern in intent["patterns"]:
            #get all the words in pattern
            wrds = nltk.word_tokenize(pattern);#returns a list with all the words in it
            words.extend(wrds) 
            docs_x.append(wrds)
            docs_y.append(intent["tag"])

        if intent["tag"] not in labels:
            labels.append(intent["tag"])

    #(Stem) find the root word by eliminating unneccessary characters in word. 
    words = [ stemmer.stem(w.lower()) for w in words if w != "?" ]
    words = sorted(list(words)) #sort and remove duplicates
    labels = sorted(labels) #sort labels

    training = []
    output = []

    out_empty = [0 for _ in range(len(labels))]

    for x, doc in enumerate(docs_x):
        bag = []
        wrds = [stemmer.stem(w) for w in doc]

        for w in words:
            if w in wrds:
                bag.append(1)
            else:
                bag.append(0)

        output_row = out_empty[:]
        output_row[labels.index(docs_y[x])] = 1

        training.append(bag)
        output.append(output_row)

    training = numpy.array(training)
    output = numpy.array(output)

    with open("data.pickle","wb") as f:
        pickle.dump((words,labels, training, output),f)


#building model
tensorflow.reset_default_graph()
#input layer
net = tflearn.input_data(shape=[None,len(training[0])])
#two hidden layers each has 8 neurons
net = tflearn.fully_connected(net,8)
net = tflearn.fully_connected(net,8)
#output layer ran through softmax activation function assigning a probability value
net = tflearn.fully_connected(net,len(output[0]),activation="softmax")
net = tflearn.regression(net)

#select Network type
model = tflearn.DNN(net)

try:
    model.load("model.tflearn")
except:
    #train model
    model.fit(training,output,n_epoch=1000,batch_size=8,show_metric=True)
    model.save("model.tflearn") #save the trained model

def bag_of_words(s,words):
    bag = [0 for _ in range(len(words))]

    s_words = nltk.word_tokenize(s)
    s_words = [stemmer.stem(word.lower()) for word in s_words]

    for se in s_words:
        for i, w in enumerate(words):
            if w == se:
                bag[i] = 1
    return numpy.array(bag)

result = model.predict([bag_of_words(php_request,words)])
result_index = numpy.argmax(result)
tag = labels[result_index]

for tg in data["intents"]:
    if tg['tag'] == tag:
        responses = tg["responses"]

print(random.choice(responses))
