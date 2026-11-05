<h3 id="website-chat">{{ __('Website Chat') }}</h3>
<table class="table table-dark-header table-bordered table-responsive">
    <tbody>
        @foreach ($rows as $row)
            <tr>
                <th>{{ $row['label'] }}</th>
                <td class="table-main-col">
                    @if ($row['ok'] === true)
                        <strong class="text-success glyphicon glyphicon-ok"></strong>
                    @elseif ($row['ok'] === false)
                        <strong class="text-danger glyphicon glyphicon-exclamation-sign"></strong>
                    @endif
                    {{ $row['detail'] }}
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
