export timestamp=$(date +%s)
export tag=logotipiwe/kb_back:time-${timestamp}

docker build . --tag=${tag} --no-cache
docker push ${tag}