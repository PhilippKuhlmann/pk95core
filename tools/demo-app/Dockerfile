# docker build -t pk95-demo:1.0.0 tools/demo-app
FROM python:3.13-alpine
WORKDIR /app
COPY app.py .
RUN adduser -D -u 1000 demo && mkdir /data && chown demo /data
USER demo
VOLUME /data
EXPOSE 8080
CMD ["python", "-u", "app.py"]
