import Model from 'flarum/common/Model';
import User from 'flarum/common/models/User';

export default class UserMoneyHistory extends Model {
  balanceDelta = Model.attribute<number>('balance_delta');
  source = Model.attribute<string>('source');
  sourceKey = Model.attribute<string | null>('source_key');
  sourceParams = Model.attribute<Record<string, unknown> | null>('source_params');
  createdAt = Model.attribute<Date, string>('created_at', Model.transformDate);
  balanceBefore = Model.attribute<number>('balance_before');
  balanceAfter = Model.attribute<number>('balance_after');
  actor = Model.hasOne<User>('actor');
}
